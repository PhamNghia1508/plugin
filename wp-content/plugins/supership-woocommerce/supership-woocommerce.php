<?php
/**
 * Plugin Name: SuperShip for WooCommerce
 * Description: Kết nối WooCommerce với SuperShip (Việt Nam): địa chỉ SuperShip ở checkout, tính cước tự động, tạo vận đơn, tracking, in nhãn và đồng bộ trạng thái đơn hàng.
 * Version: 1.0.7
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 * Text Domain: supership-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SUPERSHIP_WC_VERSION', '1.0.7' );
define( 'SUPERSHIP_WC_FILE', __FILE__ );
define( 'SUPERSHIP_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUPERSHIP_WC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once SUPERSHIP_WC_PATH . 'includes/class-supership-plugin.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'supership-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action( 'plugins_loaded', array( 'SuperShip_Plugin', 'boot' ) );

register_activation_hook( __FILE__, static function () {
	require_once SUPERSHIP_WC_PATH . 'includes/auth/class-supership-secret-storage.php';
	require_once SUPERSHIP_WC_PATH . 'includes/auth/class-supership-token-store.php';
	require_once SUPERSHIP_WC_PATH . 'includes/auth/class-supership-auth-config.php';
	require_once SUPERSHIP_WC_PATH . 'includes/auth/class-supership-auth-manager.php';
	SuperShip_Auth_Manager::maybe_migrate();

	// Tự tạo trang "Tra cứu đơn hàng" để chủ shop không phải dán shortcode thủ công.
	require_once SUPERSHIP_WC_PATH . 'includes/frontend/class-supership-order-lookup.php';
	SuperShip_Order_Lookup::install();
} );
register_deactivation_hook( __FILE__, static function () {
	$scheduler = SUPERSHIP_WC_PATH . 'includes/tracking/class-supership-tracking-scheduler.php';
	if ( file_exists( $scheduler ) ) {
		require_once $scheduler;
		SuperShip_Tracking_Scheduler::deactivate();
	}
} );
