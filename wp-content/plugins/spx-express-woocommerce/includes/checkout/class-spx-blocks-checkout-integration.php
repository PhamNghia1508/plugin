<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/** Registers the custom SPX shipping-address inner block scripts. */
final class SPX_Blocks_Checkout_Integration implements IntegrationInterface {
	public function get_name() { return 'spx-express-shipping-address'; }

	public function initialize() {
		$asset = require SPX_WC_PATH . 'assets/js/blocks-checkout-address.asset.php';
		$style_version = (string) filemtime( SPX_WC_PATH . 'assets/css/checkout-address.css' );
		wp_register_script(
			'spx-blocks-checkout-address',
			SPX_WC_URL . 'assets/js/blocks-checkout-address.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_register_style( 'spx-checkout-address', SPX_WC_URL . 'assets/css/checkout-address.css', array(), $style_version );
		wp_enqueue_style( 'spx-checkout-address' );
	}

	public function get_script_handles() { return array( 'spx-blocks-checkout-address' ); }
	public function get_editor_script_handles() { return array(); }

	public function get_script_data() {
		$service = new SPX_Checkout_Address_Service();
		return array(
			'restUrl' => esc_url_raw( rest_url( SPX_Checkout_Address_REST_Controller::NAMESPACE ) ),
			'datasetVersion' => $service->get_dataset_version(),
			'prefill' => SPX_Classic_Checkout_Address::get_prefill_for_blocks( $service ),
		);
	}
}
