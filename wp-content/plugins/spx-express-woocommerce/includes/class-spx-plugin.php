<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Plugin {
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		$files = array(
			'includes/production/class-spx-environment.php',
			'includes/production/class-spx-secret-storage.php',
			'includes/production/class-spx-credential-store.php',
			'includes/production/class-spx-production-state.php',
			'includes/production/class-spx-production-readiness.php',
			'includes/production/class-spx-order-environment.php',
			'includes/production/class-spx-environment-router.php',
			'includes/production/class-spx-credential-migration.php',
			'includes/api/class-spx-api-error-mapper.php',
			'includes/api/class-spx-api-response.php',
			'includes/api/class-spx-api-config.php',
			'includes/api/class-spx-request-signer.php',
			'includes/api/interface-spx-http-client.php',
			'includes/api/class-spx-http-client.php',
			'includes/api/class-spx-account-service.php',
			'includes/api/class-spx-rate-request-mapper.php',
			'includes/api/class-spx-rate-service.php',
			'includes/api/class-spx-client-order-id.php',
			'includes/api/class-spx-create-request-mapper.php',
			'includes/api/class-spx-tracking-status-mapper.php',
			'includes/api/class-spx-shipment-service.php',
			'includes/api/class-spx-tracking-service.php',
			'includes/api/class-spx-label-service.php',
			'includes/api/class-spx-cancel-service.php',
			'includes/status/class-spx-woo-status-mapping-policy.php',
			'includes/status/class-spx-woo-status-mapping-service.php',
			'includes/tracking/class-spx-tracking-event-repository.php',
			'includes/tracking/class-spx-tracking-route-parser.php',
			'includes/tracking/class-spx-tracking-updater.php',
			'includes/tracking/class-spx-tracking-order-query.php',
			'includes/tracking/class-spx-tracking-sync-service.php',
			'includes/tracking/class-spx-tracking-scheduler.php',
			'includes/tracking/class-spx-webhook-verifier.php',
			'includes/tracking/class-spx-webhook-controller.php',
			'includes/tracking/class-spx-customer-tracking.php',
			'includes/shipment/class-spx-label-manager.php',
			'includes/shipment/class-spx-cancel-manager.php',
			'includes/address/class-spx-xlsx-reader.php',
			'includes/address/class-spx-address-normalizer.php',
			'includes/address/class-spx-address-import-service.php',
			'includes/address/class-spx-address-repository.php',
			'includes/address/class-spx-wc-address-resolver.php',
			'includes/checkout/class-spx-checkout-eligibility.php',
			'includes/checkout/class-spx-checkout-address-service.php',
			'includes/checkout/class-spx-checkout-address-snapshot.php',
			'includes/checkout/class-spx-checkout-address-rest-controller.php',
			'includes/checkout/class-spx-classic-checkout-address.php',
			'includes/checkout/class-spx-blocks-checkout-integration.php',
			'includes/checkout/class-spx-blocks-checkout-address.php',
			'includes/rate/class-spx-fee-conversion-contract.php',
			'includes/rate/class-spx-checkout-rate-request-builder.php',
			'includes/rate/class-spx-checkout-rate-cache.php',
			'includes/rate/class-spx-dynamic-checkout-rate-service.php',
			'includes/parcel/class-spx-parcel-validation-result.php',
			'includes/parcel/class-spx-parcel-builder.php',
			'includes/class-spx-sender-profile.php',
			'includes/class-spx-order-address.php',
			'includes/class-spx-payment-resolver.php',
			'includes/class-spx-shipment-readiness.php',
			'includes/providers/interface-spx-shipping-provider.php',
			'includes/providers/class-spx-mock-provider.php',
			'includes/providers/class-spx-api-provider.php',
			'includes/class-spx-settings.php',
			'includes/class-spx-logger.php',
			'includes/class-spx-order-mapper.php',
			'includes/class-spx-shipping-method.php',
			'includes/class-spx-order-actions.php',
		);
		foreach ( $files as $file ) {
			require_once SPX_WC_PATH . $file;
		}
		SPX_Credential_Migration::maybe_migrate();

		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_method' ) );
		SPX_Woo_Status_Mapping_Policy::maybe_initialize();
		SPX_Order_Actions::init();
		SPX_Tracking_Event_Repository::install();
		SPX_Tracking_Scheduler::init();
		SPX_Webhook_Controller::init();
		SPX_Customer_Tracking::init();
		SPX_Checkout_Address_REST_Controller::init();
		SPX_Classic_Checkout_Address::init();
		SPX_Blocks_Checkout_Address::init();
		SPX_Shipping_Method::init_audit_persistence();

		if ( is_admin() ) {
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-notice-presenter.php';
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-settings-page.php';
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-production.php';
			SPX_Admin_Production::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-address.php';
			SPX_Admin_Address::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-rate.php';
			SPX_Admin_Rate::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-shipment.php';
			SPX_Admin_Shipment::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-tracking.php';
			SPX_Admin_Tracking::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-status-mapping.php';
			SPX_Admin_Status_Mapping::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-label.php';
			SPX_Admin_Label::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-cancel.php';
			SPX_Admin_Cancel::init();
			require_once SPX_WC_PATH . 'includes/admin/class-spx-admin-order-metabox.php';
			SPX_Admin_Order_Metabox::init();
		}
	}

	public static function register_shipping_method( $methods ) {
		$methods['spx_express'] = 'SPX_Shipping_Method';
		return $methods;
	}

	public static function woocommerce_missing_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'SPX Express for WooCommerce requires WooCommerce to be installed and active.', 'spx-express-woocommerce' ) . '</p></div>';
		}
	}
}
