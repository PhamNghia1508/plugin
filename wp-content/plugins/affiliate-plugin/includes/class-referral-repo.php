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

		$existing = self::get_for_order_product( $order_id, $product_id );

		if ( $existing ) {
			// A voided row means this order was refunded/cancelled and has now
			// been completed again - the commission is owed once more. Revive
			// the row (with a fresh amount) instead of leaving the affiliate
			// unpaid, which is what the UNIQUE index would otherwise cause.
			if ( self::STATUS_VOID === $existing['status'] ) {
				$revived = $wpdb->update(
					self::table(),
					array(
						'status'            => self::STATUS_PENDING,
						'commission_amount' => $amount,
						'created_at'        => current_time( 'mysql' ),
						'paid_at'           => null,
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%s', '%f', '%s', '%s' ),
					array( '%d' )
				);

				return false !== $revived;
			}

			// Already pending or paid - nothing to do, and definitely no second
			// credit for the same order.
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
	 * The referral row for one order+product, if there is one.
	 *
	 * @param int $order_id   Order ID.
	 * @param int $product_id Product ID.
	 * @return array|null
	 */
	public static function get_for_order_product( int $order_id, int $product_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE order_id = %d AND product_id = %d',
				$order_id,
				$product_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @param int $order_id   Order ID.
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function exists_for_order_product( int $order_id, int $product_id ): bool {
		return null !== self::get_for_order_product( $order_id, $product_id );
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
	 * Mark an affiliate's pending commission as paid, up to a cutoff row.
	 *
	 * The cutoff is essential: the payout screen shows a total, the shop owner
	 * transfers exactly that amount, and only then clicks "Đã trả". Any
	 * commission that lands in between must stay pending - without the cutoff it
	 * would be flagged as paid despite no money having been sent.
	 *
	 * IDs are auto-increment, so "id <= cutoff" is precisely the set of rows the
	 * owner was looking at when the page was rendered.
	 *
	 * @param int $affiliate_id    Affiliate row ID.
	 * @param int $max_referral_id Highest referral ID included in the payout.
	 * @return int Number of rows marked.
	 */
	public static function mark_affiliate_paid( int $affiliate_id, int $max_referral_id ): int {
		global $wpdb;

		if ( $max_referral_id <= 0 ) {
			return 0;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . '
				 SET status = %s, paid_at = %s
				 WHERE affiliate_id = %d AND status = %s AND id <= %d',
				self::STATUS_PAID,
				current_time( 'mysql' ),
				$affiliate_id,
				self::STATUS_PENDING,
				$max_referral_id
			)
		);

		return (int) $updated;
	}

	/**
	 * Build the shared WHERE clause for query()/count().
	 *
	 * @param array $args Filter arguments.
	 * @return array{0:string,1:array} SQL fragment and its parameters.
	 */
	private static function build_where( array $args ): array {
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

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Referral rows matching the given filters, newest first.
	 *
	 * Pass `per_page` (and `page`) to paginate - the admin list and the
	 * affiliate dashboard both do, so neither screen degrades once a busy shop
	 * has accumulated thousands of commissions.
	 *
	 * @param array $args affiliate_id, status, date_from, date_to (Y-m-d), per_page, page.
	 * @return array[]
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		list( $where, $params ) = self::build_where( $args );

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where . ' ORDER BY created_at DESC, id DESC';

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 0;
		if ( $per_page > 0 ) {
			$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows match the filters (for the pager).
	 *
	 * @param array $args Same filters as query().
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;

		list( $where, $params ) = self::build_where( $args );

		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where;

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
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
	 * `max_referral_id` is carried through to the "Đã trả" button so the payout
	 * only covers the rows that were actually on screen (see
	 * mark_affiliate_paid()).
	 *
	 * @return array[] Rows of affiliate_id, total, referral_count, max_referral_id.
	 */
	public static function pending_payouts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT affiliate_id, SUM(commission_amount) AS total, COUNT(*) AS referral_count, MAX(id) AS max_referral_id
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
