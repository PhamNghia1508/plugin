<?php
/**
 * Plugin Name: SPX Express for WooCommerce
 * Description: Kết nối WooCommerce với SPX Express (Việt Nam): địa chỉ SPX ở checkout, phí cố định, tạo vận đơn Sandbox, tracking, nhãn và đồng bộ trạng thái. Production còn khóa chờ xác minh.
 * Version: 0.9.0-rc.11
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 * Text Domain: spx-express-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SPX_WC_VERSION', '0.9.0-rc.11' );
define( 'SPX_WC_FILE', __FILE__ );
define( 'SPX_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPX_WC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once SPX_WC_PATH . 'includes/class-spx-plugin.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'spx-express-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action( 'plugins_loaded', array( 'SPX_Plugin', 'boot' ) );

register_activation_hook( __FILE__, static function () {
	require_once SPX_WC_PATH . 'includes/production/class-spx-environment.php';
	require_once SPX_WC_PATH . 'includes/production/class-spx-secret-storage.php';
	require_once SPX_WC_PATH . 'includes/production/class-spx-credential-migration.php';
	SPX_Credential_Migration::maybe_migrate();
	require_once SPX_WC_PATH . 'includes/tracking/class-spx-tracking-event-repository.php';
	SPX_Tracking_Event_Repository::install();
} );
register_deactivation_hook( __FILE__, static function () {
	require_once SPX_WC_PATH . 'includes/tracking/class-spx-tracking-scheduler.php';
	SPX_Tracking_Scheduler::deactivate();
} );
