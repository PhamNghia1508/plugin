<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Plugin Bootstrap
 *
 * Main plugin class that loads all SuperShip components and initializes
 * integration with WooCommerce.
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Plugin {

	/**
	 * Boot the plugin - called on 'plugins_loaded' hook
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		// Core files - order matters for dependencies.
		$files = array(
			// Auth & Credentials
			'includes/auth/class-supership-secret-storage.php',
			'includes/auth/class-supership-token-store.php',
			'includes/auth/class-supership-auth-config.php',
			'includes/auth/class-supership-auth-manager.php',

			// API Foundation
			'includes/api/class-supership-api-error-mapper.php',
			'includes/api/class-supership-api-response.php',
			'includes/api/interface-supership-http-client.php',
			'includes/api/class-supership-http-client.php',

			// Address System
			'includes/address/class-supership-address-normalizer.php',
			'includes/address/class-supership-address-cache.php',
			'includes/address/class-supership-address-repository.php',

			// API Services
			'includes/services/class-supership-rate-service.php',
			'includes/services/class-supership-shipment-service.php',
			'includes/services/class-supership-tracking-service.php',
			'includes/services/class-supership-label-service.php',
			'includes/services/class-supership-cancel-service.php',

			// Warehouses
			'includes/warehouse/class-supership-warehouse-service.php',

			// Status & Tracking
			'includes/tracking/class-supership-status-mapper.php',
			'includes/tracking/class-supership-tracking-scheduler.php',

			// Webhook
			'includes/webhook/class-supership-webhook-handler.php',

			// Checkout (cascading Tỉnh/Thành - Quận/Huyện - Phường/Xã dropdowns)
			'includes/checkout/class-supership-checkout-areas-rest-controller.php',
			'includes/checkout/class-supership-checkout-address-fields.php',

			// Shipping Method
			'includes/shipping/class-supership-shipping-method.php',
		);

		// Load core files (silently skip missing files during initial development).
		foreach ( $files as $file ) {
			$path = SUPERSHIP_WC_PATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		// Initialize core components (with existence checks).
		if ( class_exists( 'SuperShip_Auth_Manager' ) ) {
			SuperShip_Auth_Manager::maybe_migrate();
		}

		// Register shipping method.
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_method' ) );

		// Checkout address fields (front-end cascading dropdowns + admin order editor field).
		if ( class_exists( 'SuperShip_Checkout_Areas_REST_Controller' ) ) {
			SuperShip_Checkout_Areas_REST_Controller::init();
		}
		if ( class_exists( 'SuperShip_Checkout_Address_Fields' ) ) {
			SuperShip_Checkout_Address_Fields::init();
		}

		// Webhook endpoint - must be reachable on the front end (REST API), not admin-gated.
		if ( class_exists( 'SuperShip_Webhook_Handler' ) ) {
			( new SuperShip_Webhook_Handler() )->register_endpoint();
		}

		// WP-Cron tracking sync - runs via wp-cron.php, not admin-gated.
		if ( class_exists( 'SuperShip_Tracking_Scheduler' ) ) {
			SuperShip_Tracking_Scheduler::init();
		}

		// Admin UI.
		if ( is_admin() ) {
			self::load_admin_components();
		}
	}

	/**
	 * Load admin-only components
	 */
	private static function load_admin_components() {
		$admin_files = array(
			'includes/admin/class-supership-admin-settings.php',
			'includes/admin/class-supership-order-actions.php',
		);

		foreach ( $admin_files as $file ) {
			$path = SUPERSHIP_WC_PATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'SuperShip_Admin_Settings' ) ) {
			( new SuperShip_Admin_Settings() )->init();
		}

		if ( class_exists( 'SuperShip_Order_Actions' ) ) {
			( new SuperShip_Order_Actions() )->init();
		}
	}

	/**
	 * Register SuperShip shipping method with WooCommerce
	 *
	 * @param array $methods Existing shipping methods
	 * @return array Modified shipping methods
	 */
	public static function register_shipping_method( $methods ) {
		if ( class_exists( 'SuperShip_Shipping_Method' ) ) {
			$methods['supership'] = 'SuperShip_Shipping_Method';
		}
		return $methods;
	}

	/**
	 * Display admin notice when WooCommerce is not active
	 */
	public static function woocommerce_missing_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'SuperShip for WooCommerce requires WooCommerce to be installed and active.', 'supership-woocommerce' );
			echo '</p></div>';
		}
	}
}
