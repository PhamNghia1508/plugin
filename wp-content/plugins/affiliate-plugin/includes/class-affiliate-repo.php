<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for the {prefix}affiliates table.
 *
 * All queries go through $wpdb->prepare(). Callers get plain arrays back.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Repo {

	const STATUS_PENDING  = 'pending';
	const STATUS_ACTIVE   = 'active';
	const STATUS_DISABLED = 'disabled';

	/**
	 * Table name including the site prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'affiliates';
	}

	/**
	 * Human label for a status value.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::STATUS_PENDING  => __( 'Chờ duyệt', 'wc-affiliate' ),
			self::STATUS_ACTIVE   => __( 'Đang hoạt động', 'wc-affiliate' ),
			self::STATUS_DISABLED => __( 'Đã khoá', 'wc-affiliate' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Create an affiliate record for a WordPress user.
	 *
	 * New affiliates always start as "pending" - they can't earn anything until
	 * the shop owner approves them.
	 *
	 * @param int $user_id WP user ID.
	 * @return int|WP_Error New affiliate row ID, or an error.
	 */
	public static function create( int $user_id ) {
		global $wpdb;

		$existing = self::get_by_user_id( $user_id );
		if ( $existing ) {
			return new WP_Error( 'already_affiliate', __( 'Tài khoản này đã đăng ký cộng tác viên rồi.', 'wc-affiliate' ) );
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'    => $user_id,
				'ref_code'   => self::generate_ref_code(),
				'status'     => self::STATUS_PENDING,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'insert_failed', __( 'Không tạo được hồ sơ cộng tác viên, vui lòng thử lại.', 'wc-affiliate' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * A short, unambiguous referral code.
	 *
	 * Excludes characters people confuse when copying by hand (0/O, 1/I/L) and
	 * retries until the code is unused, so the UNIQUE index can never reject it.
	 */
	private static function generate_ref_code(): string {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$max      = strlen( $alphabet ) - 1;

		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ wp_rand( 0, $max ) ];
			}

			if ( ! self::get_by_ref_code( $code ) ) {
				return $code;
			}
		}

		// Practically unreachable; fall back to something guaranteed unique.
		return 'AF' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 10 ) );
	}

	/**
	 * @param string $code Referral code from the ?ref= parameter.
	 * @return array|null
	 */
	public static function get_by_ref_code( string $code ): ?array {
		global $wpdb;

		if ( '' === $code ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ref_code = %s', $code ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @param int $user_id WP user ID.
	 * @return array|null
	 */
	public static function get_by_user_id( int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @param int $id Affiliate row ID.
	 * @return array|null
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Whether an affiliate may earn commission right now.
	 *
	 * @param array|null $affiliate Row from this table.
	 * @return bool
	 */
	public static function is_active( ?array $affiliate ): bool {
		return $affiliate && self::STATUS_ACTIVE === $affiliate['status'];
	}

	/**
	 * Change an affiliate's status (approve / disable).
	 *
	 * @param int    $id     Affiliate row ID.
	 * @param string $status One of the STATUS_* constants.
	 * @return bool
	 */
	public static function update_status( int $id, string $status ): bool {
		global $wpdb;

		$allowed = array( self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_DISABLED );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * All affiliates, newest first, optionally filtered by status.
	 *
	 * @param string $status Optional status filter ('' = all).
	 * @return array[]
	 */
	public static function get_all( string $status = '' ): array {
		global $wpdb;

		$sql = 'SELECT * FROM ' . self::table();

		if ( '' !== $status ) {
			$sql = $wpdb->prepare( $sql . ' WHERE status = %s ORDER BY created_at DESC', $status );
		} else {
			$sql .= ' ORDER BY created_at DESC';
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Display name for an affiliate row (falls back to the login/email).
	 *
	 * @param array $affiliate Affiliate row.
	 * @return string
	 */
	public static function display_name( array $affiliate ): string {
		$user = get_userdata( (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return __( '(tài khoản đã xoá)', 'wc-affiliate' );
		}

		return $user->display_name ? $user->display_name : $user->user_login;
	}
}
