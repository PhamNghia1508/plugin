<?php
/** Phase 6R-B runtime registration contract (PHP 7.4 compatible). */
error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ );
define( 'SPX_WC_PATH', dirname( __DIR__ ) . '/' );
define( 'SPX_WC_URL', 'https://plugin.test/spx-express-woocommerce/' );

eval( 'namespace Automattic\\WooCommerce\\Blocks\\Integrations; interface IntegrationInterface { public function get_name(); public function initialize(); public function get_script_handles(); public function get_editor_script_handles(); public function get_script_data(); }' );

$GLOBALS['spx_registered_scripts'] = array();
$GLOBALS['spx_registered_styles']  = array();

function wp_register_script( $handle, $src, $deps, $version, $in_footer ) {
	$GLOBALS['spx_registered_scripts'][] = compact( 'handle', 'src', 'deps', 'version', 'in_footer' );
}
function wp_register_style( $handle, $src, $deps, $version ) {
	$GLOBALS['spx_registered_styles'][] = compact( 'handle', 'src', 'deps', 'version' );
}
function wp_enqueue_style( $handle ) {}

function spx_blocks_registration_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$asset_path = SPX_WC_PATH . 'assets/js/blocks-checkout-address.asset.php';
spx_blocks_registration_assert( is_file( $asset_path ), 'Blocks build emits an asset metadata file' );
$asset = require $asset_path;

require_once SPX_WC_PATH . 'includes/checkout/class-spx-blocks-checkout-integration.php';
$integration = new SPX_Blocks_Checkout_Integration();
$integration->initialize();

spx_blocks_registration_assert( 1 === count( $GLOBALS['spx_registered_scripts'] ), 'integration registers exactly one script' );
$registered = $GLOBALS['spx_registered_scripts'][0];
spx_blocks_registration_assert( 'spx-blocks-checkout-address' === $registered['handle'], 'runtime handle is deterministic' );
spx_blocks_registration_assert( SPX_WC_URL . 'assets/js/blocks-checkout-address.js' === $registered['src'], 'runtime URL maps to the built bundle' );
spx_blocks_registration_assert( $asset['dependencies'] === $registered['deps'], 'runtime dependencies come from build metadata' );
spx_blocks_registration_assert( (string) $asset['version'] === $registered['version'], 'runtime version comes from build metadata' );
spx_blocks_registration_assert( true === $registered['in_footer'], 'Blocks bundle remains a footer script' );
spx_blocks_registration_assert( array( 'spx-blocks-checkout-address' ) === $integration->get_script_handles(), 'frontend integration returns the registered handle once' );
spx_blocks_registration_assert( array() === $integration->get_editor_script_handles(), 'frontend-only bundle is not returned as an editor script' );

echo "\nAll Phase 6R-B Blocks registration assertions passed.\n";

