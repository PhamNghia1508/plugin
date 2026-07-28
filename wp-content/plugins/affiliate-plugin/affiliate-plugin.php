<?php
/**
 * Plugin Name: Affiliate cho WooCommerce
 * Description: Cộng tác viên đăng ký, lấy link giới thiệu theo từng sản phẩm và nhận hoa hồng cố định (VND) khi đơn hàng hoàn thành. Quản lý duyệt cộng tác viên, theo dõi hoa hồng và chi trả thủ công.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 * Text Domain: wc-affiliate
 * Domain Path: /languages
 *
 * @package WC_Affiliate
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_AFFILIATE_VERSION', '1.0.0' );
define( 'WC_AFFILIATE_FILE', __FILE__ );
define( 'WC_AFFILIATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_AFFILIATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 * The plugin reads/writes order data exclusively through the WC_Order CRUD,
 * so it works the same whether orders live in posts or the custom tables.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Load the plugin's classes and wire up the hooks.
 *
 * Runs on `plugins_loaded` so WooCommerce is already available; without it the
 * plugin quietly does nothing except show an admin notice.
 */
function wc_affiliate_boot(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Plugin "Affiliate cho WooCommerce" cần WooCommerce được cài đặt và kích hoạt thì mới hoạt động.', 'wc-affiliate' );
				echo '</p></div>';
			}
		);
		return;
	}

	$files = array(
		'includes/class-activator.php',
		'includes/class-affiliate-repo.php',
		'includes/class-referral-repo.php',
		'includes/class-affiliate-role.php',
		'includes/class-product-meta.php',
		'includes/class-tracking.php',
		'includes/class-order-hooks.php',
		'admin/class-admin-menu.php',
		'public/class-shortcodes.php',
	);

	foreach ( $files as $file ) {
		$path = WC_AFFILIATE_PATH . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	WC_Affiliate_Role::init();
	WC_Affiliate_Product_Meta::init();
	WC_Affiliate_Tracking::init();
	WC_Affiliate_Order_Hooks::init();
	WC_Affiliate_Shortcodes::init();

	if ( is_admin() ) {
		WC_Affiliate_Admin_Menu::init();
	}
}
add_action( 'plugins_loaded', 'wc_affiliate_boot' );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'wc-affiliate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Activation: create the tables, register the affiliate role and publish the
 * three front-end pages, so the shop owner has a working affiliate programme
 * immediately after clicking "Kích hoạt" - no shortcode pasting required.
 */
register_activation_hook(
	__FILE__,
	static function () {
		require_once WC_AFFILIATE_PATH . 'includes/class-activator.php';
		require_once WC_AFFILIATE_PATH . 'includes/class-affiliate-role.php';
		WC_Affiliate_Activator::activate();
	}
);
