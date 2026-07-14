<?php
/** Network-free Phase 6M checkout-address domain tests (PHP 7.4 compatible). */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'address/class-spx-address-normalizer.php';
require_once $base . 'address/class-spx-address-import-service.php';
require_once $base . 'address/class-spx-address-repository.php';
require_once $base . 'checkout/class-spx-checkout-eligibility.php';
require_once $base . 'checkout/class-spx-checkout-address-service.php';

$GLOBALS['spx_n'] = 0;
function ct( $condition, $message ) {
	$GLOBALS['spx_n']++;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo "PASS: {$message}\n";
}

function checkout_row( $id, $ward, $district, $province, $delivery = 'Y', $cod = 'Y', $status = 'Available' ) {
	return array( 'id' => (string) $id, 'ward' => $ward, 'district' => $district, 'province' => $province, 'delivery' => $delivery, 'pickup' => 'Y', 'cod' => $cod, 'status' => $status );
}

$built = ( new SPX_Address_Import_Service() )->validate_and_build(
	array(
		checkout_row( 101, 'Phường Một', 'Quận A', 'TP Hồ Chí Minh' ),
		checkout_row( 102, 'Phường Không COD', 'Quận A', 'TP Hồ Chí Minh', 'Y', 'N' ),
		checkout_row( 103, 'Phường Không Giao', 'Quận A', 'TP Hồ Chí Minh', 'N', 'Y' ),
		checkout_row( 104, 'Phường Ngưng', 'Quận A', 'TP Hồ Chí Minh', 'Y', 'Y', 'Unavailable' ),
	)
);
$dataset         = $built['dataset'];
$dataset['meta'] = array( 'version' => '20260429' );
$repo            = SPX_Address_Repository::from_array( $dataset );
$service         = new SPX_Checkout_Address_Service( $repo );

$provinces = $service->get_provinces();
ct( 1 === count( $provinces ), 'service returns canonical provinces' );
ct( array( 'id', 'label' ) === array_keys( $provinces[0] ), 'province response is allowlisted' );
$pid = $provinces[0]['id'];
$districts = $service->get_districts( $pid );
ct( 1 === count( $districts ) && array( 'id', 'label' ) === array_keys( $districts[0] ), 'district response is allowlisted' );
$did = $districts[0]['id'];
$wards = $service->get_wards( $pid, $did );
ct( 4 === count( $wards ), 'ward listing is constrained to canonical hierarchy' );
ct( array( 'id', 'label', 'active', 'delivery_supported', 'cod_supported' ) === array_keys( $wards[0] ), 'ward response exposes only UI capabilities' );
ct( '20260429' === $service->get_dataset_version(), 'service reports canonical dataset version' );

$ok = $service->validate_selection( $pid, $did, '101', false, '20260429' );
ct( true === $ok['valid'] && ! isset( $ok['snapshot']['source'] ), 'shared validation does not infer a checkout entry-point source' );
ct( 'Phường Một' === $ok['snapshot']['ward_name'], 'snapshot ignores client labels and uses canonical ward name' );
ct( true === $ok['snapshot']['delivery_supported'] && true === $ok['snapshot']['cod_supported'], 'snapshot carries canonical capabilities' );

ct( 'dataset_version_mismatch' === $service->validate_selection( $pid, $did, '101', false, 'old' )['error'], 'stale dataset version fails closed' );
ct( 'hierarchy_invalid' === $service->validate_selection( 'p_bad', $did, '101', false, '20260429' )['error'], 'tampered hierarchy fails' );
ct( 'delivery_unsupported' === $service->validate_selection( $pid, $did, '103', false, '20260429' )['error'], 'delivery capability is enforced' );
ct( 'ward_unavailable' === $service->validate_selection( $pid, $did, '104', false, '20260429' )['error'], 'status capability is enforced' );
ct( 'cod_unsupported' === $service->validate_selection( $pid, $did, '102', true, '20260429' )['error'], 'COD capability is enforced for COD checkout' );
ct( true === $service->validate_selection( $pid, $did, '102', false, '20260429' )['valid'], 'COD capability does not block prepaid checkout' );

ct( SPX_Checkout_Eligibility::requires_selection( true, array( 'spx_express:3' ) ), 'SPX instance rate requires selection' );
ct( SPX_Checkout_Eligibility::requires_selection( true, array( 'spx_express' ) ), 'SPX base rate requires selection' );
ct( ! SPX_Checkout_Eligibility::requires_selection( true, array( 'flat_rate:1' ) ), 'non-SPX rate bypasses selection' );
ct( ! SPX_Checkout_Eligibility::requires_selection( false, array( 'spx_express:3' ) ), 'virtual/no-shipping cart bypasses selection' );
ct( ! SPX_Checkout_Eligibility::requires_selection( true, array( 'local_pickup:2' ) ), 'local pickup bypasses selection' );

$classic_js = file_get_contents( dirname( __DIR__ ) . '/assets/js/classic-checkout-address.js' );
$blocks_js = file_get_contents( dirname( __DIR__ ) . '/assets/js/blocks-checkout-address.js' );
$blocks_php = file_get_contents( dirname( __DIR__ ) . '/includes/checkout/class-spx-blocks-checkout-address.php' );
$classic_php = file_get_contents( dirname( __DIR__ ) . '/includes/checkout/class-spx-classic-checkout-address.php' );
ct( false !== strpos( $classic_js, "trigger( 'update_checkout' )" ), 'Classic ward change requests a server-side rate recalculation' );
ct( false !== strpos( $blocks_js, 'extensionCartUpdate' ), 'Blocks selection requests a Store API cart recalculation' );
ct( false !== strpos( $blocks_js, 'rateUpdateSequence = rateUpdateSequence.then' ), 'Blocks rate recalculations are serialized to prevent stale responses winning a race' );
ct( false !== strpos( $blocks_php, 'woocommerce_store_api_register_update_callback' ), 'Blocks registers a server callback for canonical session selection' );
ct( false !== strpos( $classic_php, 'shipping_for_package_' ), 'canonical selection change invalidates WooCommerce shipping-package cache' );

echo "\nAll Phase 6M checkout-address domain tests passed ({$GLOBALS['spx_n']} assertions).\n";
