<?php
defined( 'ABSPATH' ) || exit;

/**
 * Where a referral turns into money: attaching the affiliate to the order at
 * checkout, awarding commission when the order completes, and voiding it if the
 * order is later refunded or cancelled.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Order_Hooks {

	const ORDER_META = '_affiliate_ref';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Classic checkout and the block/Store API checkout hand the order over
		// through different hooks - both are needed or orders placed through one
		// of the two checkouts would never be attributed.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'attach_referral' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attach_referral_blocks' ) );

		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'award_commission' ), 10, 2 );

		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'revoke_commission' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'revoke_commission' ), 10, 2 );
	}

	/**
	 * Classic checkout: copy the referral cookie onto the order.
	 *
	 * @param int   $order_id    Order ID.
	 * @param array $posted_data Posted checkout data (unused).
	 * @param mixed $order       Order object.
	 */
	public static function attach_referral( $order_id, $posted_data = array(), $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::store_referral_on_order( $order );
		}
	}

	/**
	 * Block/Store API checkout: same job, different signature.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function attach_referral_blocks( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::store_referral_on_order( $order );
		}
	}

	/**
	 * Snapshot the referral cookie onto the order.
	 *
	 * Attribution is frozen here, at purchase time - not at completion - so
	 * clearing cookies or clicking someone else's link afterwards can't move the
	 * commission to a different affiliate.
	 *
	 * The referral is only consumed when this order actually contains the
	 * referred product. Otherwise the cookie is left intact for the rest of its
	 * 30 days: a visitor who clicks a link for product A, buys product B today
	 * and comes back for A next week must still earn the affiliate their
	 * commission.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function store_referral_on_order( WC_Order $order ): void {
		if ( $order->get_meta( self::ORDER_META ) ) {
			return;
		}

		$referral = WC_Affiliate_Tracking::get_referral();
		if ( ! $referral ) {
			return;
		}

		if ( ! self::order_contains_product( $order, (int) $referral['product_id'] ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, $referral );
		$order->save();

		WC_Affiliate_Tracking::clear_cookie();
	}

	/**
	 * Whether an order contains a given product.
	 *
	 * Uses get_product_id(), which resolves to the parent for variations - the
	 * referral link points at the parent product page, so buying any variation
	 * of it counts.
	 *
	 * @param WC_Order $order      Order object.
	 * @param int      $product_id Product to look for.
	 * @return bool
	 */
	private static function order_contains_product( WC_Order $order, int $product_id ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === $product_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Award commission when an order reaches "completed".
	 *
	 * Business rules applied here:
	 * - only the exact product from the referral link earns anything; other
	 *   products in the same order are ignored;
	 * - the commission is paid once per order, never multiplied by quantity;
	 * - the amount is snapshotted now, so later edits to the product's
	 *   commission don't rewrite what this affiliate is owed;
	 * - an affiliate buying through their own link earns nothing.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order object.
	 */
	public static function award_commission( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$referral = $order->get_meta( self::ORDER_META );
		if ( ! is_array( $referral ) || empty( $referral['affiliate_id'] ) || empty( $referral['product_id'] ) ) {
			return;
		}

		$affiliate_id = (int) $referral['affiliate_id'];
		$product_id   = (int) $referral['product_id'];

		$affiliate = WC_Affiliate_Repo::get_by_id( $affiliate_id );
		if ( ! WC_Affiliate_Repo::is_active( $affiliate ) ) {
			return;
		}

		if ( self::is_self_purchase( $order, $affiliate ) ) {
			$order->add_order_note(
				__( 'Cộng tác viên: không tính hoa hồng vì đơn này do chính cộng tác viên đặt qua link của mình.', 'wc-affiliate' )
			);
			return;
		}

		if ( ! self::order_contains_product( $order, $product_id ) ) {
			return;
		}

		$commission = WC_Affiliate_Product_Meta::get_commission( $product_id );
		if ( $commission <= 0 ) {
			return;
		}

		$recorded = WC_Affiliate_Referral_Repo::record( $affiliate_id, $order->get_id(), $product_id, $commission );

		// record() returns false when this order+product was already credited -
		// e.g. the order was moved out of "completed" and back again. Staying
		// quiet there keeps the order notes free of duplicates.
		if ( ! $recorded ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: affiliate name, 2: commission amount */
				__( 'Cộng tác viên: đã ghi nhận hoa hồng %2$s cho %1$s.', 'wc-affiliate' ),
				WC_Affiliate_Repo::display_name( $affiliate ),
				wp_strip_all_tags( wc_price( $commission ) )
			)
		);
	}

	/**
	 * Void unpaid commission when an order is refunded or cancelled.
	 *
	 * Commission that has already been paid out is left untouched on purpose -
	 * the money is gone, so the plugin just leaves a note and lets the shop
	 * owner decide whether to claw it back.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order object.
	 */
	public static function revoke_commission( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$voided = WC_Affiliate_Referral_Repo::void_pending_for_order( $order->get_id() );

		if ( $voided > 0 ) {
			$order->add_order_note( __( 'Cộng tác viên: đã huỷ hoa hồng chưa chi trả của đơn này.', 'wc-affiliate' ) );
		}

		if ( WC_Affiliate_Referral_Repo::has_paid_for_order( $order->get_id() ) ) {
			$order->add_order_note(
				__( 'Cộng tác viên: đơn này có hoa hồng ĐÃ CHI TRẢ. Plugin không tự thu hồi — vui lòng xử lý thủ công nếu cần.', 'wc-affiliate' )
			);
		}
	}

	/**
	 * Whether the buyer is the affiliate themselves.
	 *
	 * Checks the account first, then the billing email, so signing out and
	 * ordering as a guest with the same address doesn't slip through.
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $affiliate Affiliate row.
	 * @return bool
	 */
	private static function is_self_purchase( WC_Order $order, array $affiliate ): bool {
		$affiliate_user_id = (int) $affiliate['user_id'];

		if ( $affiliate_user_id > 0 && (int) $order->get_customer_id() === $affiliate_user_id ) {
			return true;
		}

		$user = get_userdata( $affiliate_user_id );
		if ( ! $user || ! $user->user_email ) {
			return false;
		}

		$order_email = trim( strtolower( (string) $order->get_billing_email() ) );

		return '' !== $order_email && $order_email === strtolower( $user->user_email );
	}
}
