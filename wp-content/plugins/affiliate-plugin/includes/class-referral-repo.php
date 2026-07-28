<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for the {prefix}affiliate_referrals table - one row per
 * commission earned.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Referral_Repo {

	const STATUS_PENDING = 'pending';
	const STATUS_PAID    = 'paid';
	const STATUS_VOID    = 'void';

	/**
	 * Table name including the site prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'affiliate_referrals';
	}

	/**
	 * Human label for a status value.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::STATUS_PENDING => __( 'Chờ chi trả', 'wc-affiliate' ),
			self::STATUS_PAID    => __( 'Đã trả', 'wc-affiliate' ),
			self::STATUS_VOID    => __( 'Đã huỷ', 'wc-affiliate' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Record a commission.
	 *
	 * `commission_amount` is a snapshot taken when the order completes, so
	 * editing the product's commission later never rewrites history.
	 *
	 * Returns false when a row for this order+product already exists - the
	 * UNIQUE index makes the double-credit impossible, and callers treat that
	 * as "nothing more to do" rather than an error.
	 *
	 * @param int   $affiliate_id Affiliate row ID.
	 * @param int   $order_id     WooCommerce order ID.
	 * @param int   $product_id   Product the commission is for.
	 * @param float $amount       Commission in VND.
	 * @return bool
	 */
	public static function record( int $affiliate_id, int $order_id, int $product_id, float $amount ): bool {
		global $wpdb;

		if ( self::exists_for_order_product( $order_id, $product_id ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'affiliate_id'      => $affiliate_id,
				'order_id'          => $order_id,
				'product_id'        => $product_id,
				'commission_amount' => $amount,
				'status'            => self::STATUS_PENDING,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%f', '%s', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * @param int $order_id   Order ID.
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function exists_for_order_product( int $order_id, int $product_id ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE order_id = %d AND product_id = %d',
				$order_id,
				$product_id
			)
		);

		return null !== $found;
	}

	/**
	 * Void every still-unpaid commission attached to an order.
	 *
	 * Used when an order is refunded or cancelled. Already-paid commissions are
	 * deliberately left alone (money has left the building) - the caller logs an
	 * order note so the shop owner can decide whether to claw it back by hand.
	 *
	 * @param int $order_id Order ID.
	 * @return int Number of rows voided.
	 */
	public static function void_pending_for_order( int $order_id ): int {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array( 'status' => self::STATUS_VOID ),
			array(
				'order_id' => $order_id,
				'status'   => self::STATUS_PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return (int) $updated;
	}

	/**
	 * Whether an order still has commissions that were already paid out.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function has_paid_for_order( int $order_id ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE order_id = %d AND status = %s',
				$order_id,
				self::STATUS_PAID
			)
		);

		return null !== $found;
	}

	/**
	 * Mark every pending commission of one affiliate as paid.
	 *
	 * @param int $affiliate_id Affiliate row ID.
	 * @return int Number of rows marked.
	 */
	public static function mark_affiliate_paid( int $affiliate_id ): int {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'status'  => self::STATUS_PAID,
				'paid_at' => current_time( 'mysql' ),
			),
			array(
				'affiliate_id' => $affiliate_id,
				'status'       => self::STATUS_PENDING,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return (int) $updated;
	}

	/**
	 * Referral rows matching the given filters, newest first.
	 *
	 * @param array $args affiliate_id, status, date_from, date_to (Y-m-d).
	 * @return array[]
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['affiliate_id'] ) ) {
			$where[]  = 'affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Commission totals for one affiliate (or the whole shop when 0).
	 *
	 * @param int $affiliate_id Affiliate row ID, or 0 for every affiliate.
	 * @return array{pending: float, paid: float}
	 */
	public static function totals( int $affiliate_id = 0 ): array {
		global $wpdb;

		$sql    = 'SELECT status, SUM(commission_amount) AS total FROM ' . self::table();
		$params = array();

		if ( $affiliate_id > 0 ) {
			$sql     .= ' WHERE affiliate_id = %d';
			$params[] = $affiliate_id;
		}

		$sql .= ' GROUP BY status';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$totals = array(
			'pending' => 0.0,
			'paid'    => 0.0,
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $totals[ $row['status'] ] ) ) {
				$totals[ $row['status'] ] = (float) $row['total'];
			}
		}

		return $totals;
	}

	/**
	 * Affiliates that currently have unpaid commission, with their totals -
	 * this is what the payout screen lists.
	 *
	 * @return array[] Rows of affiliate_id, total, referral_count.
	 */
	public static function pending_payouts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT affiliate_id, SUM(commission_amount) AS total, COUNT(*) AS referral_count
				 FROM ' . self::table() . '
				 WHERE status = %s
				 GROUP BY affiliate_id
				 ORDER BY total DESC',
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
