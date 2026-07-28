<?php
defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated: creates the custom tables, registers
 * the affiliate role and publishes the front-end pages.
 *
 * Everything here is idempotent - activating again (or after an update) is safe
 * and never duplicates tables, roles or pages.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Activator {

	/** Option holding the DB schema version, so future updates can migrate. */
	const DB_VERSION_OPTION = 'wc_affiliate_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Pages the plugin publishes on activation: option key => [title, shortcode].
	 * Stored by ID so the owner can rename/move them without breaking anything.
	 */
	const PAGES = array(
		'wc_affiliate_page_register'  => array( 'Đăng ký cộng tác viên', 'affiliate_register' ),
		'wc_affiliate_page_login'     => array( 'Đăng nhập cộng tác viên', 'affiliate_login' ),
		'wc_affiliate_page_dashboard' => array( 'Trang cộng tác viên', 'affiliate_dashboard' ),
	);

	/**
	 * Entry point for register_activation_hook().
	 */
	public static function activate(): void {
		self::create_tables();
		WC_Affiliate_Role::add_role();
		self::create_pages();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create/upgrade the two custom tables.
	 *
	 * Uses dbDelta, which compares the SQL against the live schema and only
	 * applies differences - that is what makes re-activation safe. The SQL
	 * formatting (two spaces after PRIMARY KEY, lowercase types, KEY not INDEX)
	 * is deliberate: dbDelta parses the string with regexes and silently
	 * mis-handles other formatting.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$affiliates      = $wpdb->prefix . 'affiliates';
		$referrals       = $wpdb->prefix . 'affiliate_referrals';

		// status: pending | active | disabled
		$sql_affiliates = "CREATE TABLE {$affiliates} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL,
			ref_code varchar(32) NOT NULL,
			status varchar(20) NOT NULL default 'pending',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ref_code (ref_code),
			UNIQUE KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		// status: pending | paid | void
		// The UNIQUE KEY on (order_id, product_id) is the guard against
		// double-crediting: WooCommerce can fire the status-completed hook more
		// than once for the same order, and an admin can move an order out of
		// and back into "completed". The insert is written to ignore duplicates.
		$sql_referrals = "CREATE TABLE {$referrals} (
			id bigint(20) unsigned NOT NULL auto_increment,
			affiliate_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			commission_amount decimal(12,2) NOT NULL default '0.00',
			status varchar(20) NOT NULL default 'pending',
			created_at datetime NOT NULL,
			paid_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_product (order_id,product_id),
			KEY affiliate_id (affiliate_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql_affiliates );
		dbDelta( $sql_referrals );
	}

	/**
	 * Publish the register / login / dashboard pages if they don't exist yet.
	 *
	 * Mirrors the behaviour a non-technical shop owner expects: activate the
	 * plugin and the affiliate programme is immediately reachable, instead of
	 * having to know that shortcodes exist and where to paste them.
	 */
	private static function create_pages(): void {
		foreach ( self::PAGES as $option => $page ) {
			list( $title, $shortcode ) = $page;

			$existing_id = (int) get_option( $option, 0 );
			if ( $existing_id > 0 ) {
				$post = get_post( $existing_id );
				if ( $post && 'page' === $post->post_type ) {
					// Restore + republish if it was trashed/drafted, otherwise leave it alone.
					if ( in_array( $post->post_status, array( 'trash', 'draft', 'pending' ), true ) ) {
						if ( 'trash' === $post->post_status ) {
							wp_untrash_post( $existing_id );
						}
						wp_update_post(
							array(
								'ID'          => $existing_id,
								'post_status' => 'publish',
							)
						);
					}
					continue;
				}
			}

			// The owner may have created the page by hand already - reuse it
			// rather than publishing a duplicate.
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					's'              => '[' . $shortcode . ']',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $found ) ) {
				update_option( $option, (int) $found[0] );
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => '[' . $shortcode . ']',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( $option, (int) $page_id );
			}
		}
	}

	/**
	 * Permalink of one of the plugin's pages ('' when it no longer exists).
	 *
	 * @param string $option One of the keys in self::PAGES.
	 * @return string
	 */
	public static function get_page_url( string $option ): string {
		$page_id = (int) get_option( $option, 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		return '';
	}
}
