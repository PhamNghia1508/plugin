<?php
defined( 'ABSPATH' ) || exit;

/**
 * The custom "affiliate" role, plus the guards that keep affiliates out of
 * wp-admin.
 *
 * Affiliates are real WordPress users (so login/password reset come for free)
 * but they must never see the WordPress dashboard - they belong on the
 * plugin's own front-end dashboard page.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Role {

	const ROLE = 'affiliate';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'block_admin_access' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
	}

	/**
	 * Create the role. Called on activation; safe to call repeatedly because
	 * add_role() is a no-op when the role already exists.
	 */
	public static function add_role(): void {
		add_role(
			self::ROLE,
			__( 'Cộng tác viên', 'wc-affiliate' ),
			array(
				// Just enough to be a logged-in user. No admin capabilities at
				// all - an affiliate must not be able to see orders, products
				// or other customers' data.
				'read' => true,
			)
		);
	}

	/**
	 * Whether the current (or given) user is an affiliate and nothing more.
	 *
	 * The `manage_woocommerce` check matters: the shop owner may well sign up
	 * as an affiliate to test the flow, and locking themselves out of wp-admin
	 * would be a nasty surprise.
	 *
	 * @param int|null $user_id User to check, or null for the current user.
	 * @return bool
	 */
	public static function is_affiliate( ?int $user_id = null ): bool {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}

		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Send affiliates back to their own dashboard if they try to open wp-admin.
	 *
	 * AJAX is explicitly allowed through: admin-ajax.php runs admin_init too,
	 * and front-end features (WooCommerce cart fragments, this plugin's own
	 * copy-link handler) would break if it were redirected.
	 */
	public static function block_admin_access(): void {
		if ( wp_doing_ajax() || ! self::is_affiliate() ) {
			return;
		}

		$dashboard = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_dashboard' );
		wp_safe_redirect( $dashboard ? $dashboard : home_url() );
		exit;
	}

	/**
	 * Hide the black admin toolbar for affiliates - it only exposes links they
	 * cannot open anyway.
	 *
	 * @param bool $show Whether to show the bar.
	 * @return bool
	 */
	public static function hide_admin_bar( $show ) {
		return self::is_affiliate() ? false : $show;
	}
}
