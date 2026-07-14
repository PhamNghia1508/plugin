<?php
/**
 * Lightweight, network-free tests for SPX rate mapping + service.
 * A fake transport (SPX_Http_Client_Interface) stands in for the network.
 *   docker run --rm -v "$PWD":/app -w /app php:7.4-cli \
 *     php wp-content/plugins/spx-express-woocommerce/tests/test-spx-rate.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $t, $d = null ) { return $t; }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function is_wp_error( $t ) { return is_object( $t ) && method_exists( $t, 'get_error_message' ); }

$api = dirname( __DIR__ ) . '/includes/api/';
$production = dirname( __DIR__ ) . '/includes/production/';
require_once $production . 'class-spx-environment.php';
require_once $production . 'class-spx-secret-storage.php';
require_once $production . 'class-spx-credential-store.php';
$adr = dirname( __DIR__ ) . '/includes/address/';
require_once $api . 'class-spx-api-error-mapper.php';
require_once $api . 'class-spx-api-response.php';
require_once $api . 'class-spx-api-config.php';
require_once $api . 'class-spx-request-signer.php';
require_once $api . 'interface-spx-http-client.php';
require_once $api . 'class-spx-rate-request-mapper.php';
require_once $api . 'class-spx-rate-service.php';
require_once $adr . 'class-spx-address-normalizer.php';
require_once $adr . 'class-spx-address-import-service.php';
require_once $adr . 'class-spx-address-repository.php';

$GLOBALS['n'] = 0;
function t( $c, $m ) { $GLOBALS['n']++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: {$m}\n"; }

class SPX_Fake_Client implements SPX_Http_Client_Interface {
	public $response; public $last_path = ''; public $last_body = array();
	public function request( string $path, array $payload ): SPX_API_Response { $this->last_path = $path; $this->last_body = $payload; return $this->response; }
}
function resp_ok( $orders, $fail = array() ) { return SPX_API_Response::from_http( 200, json_encode( array( 'ret_code' => 0, 'data' => array( 'orders' => $orders, 'fail_list' => $fail ) ) ) ); }

/* ---- Request mapper (pure) ---- */
$base = array(
	'service_type' => 1, 'collect_type' => 2, 'is_cod' => false, 'cod_amount' => 0, 'insured_value' => 0,
	'weight_grams' => 500, 'parcel_item_name' => 'Áo', 'parcel_item_quantity' => 3,
	'sender'    => array( 'province' => 'TP. Hồ Chí Minh', 'district' => 'Quận 1', 'ward' => 'Phường Bến Nghé', 'address' => 'S1' ),
	'recipient' => array( 'province' => 'Hà Nội', 'district' => 'Quận Ba Đình', 'ward' => 'Phường Ngọc Hà', 'address' => 'S2' ),
);
$o = SPX_Rate_Request_Mapper::map_order( $base );
t( 1 === $o['base_info']['service_type'], 'mapper sets service_type' );
t( 'TP. Hồ Chí Minh' === $o['sender_info']['sender_state'] && 'Quận 1' === $o['sender_info']['sender_city'] && 'Phường Bến Nghé' === $o['sender_info']['sender_district'], 'sender province/district/ward -> state/city/district' );
t( 'Hà Nội' === $o['deliver_info']['deliver_state'] && 'Phường Ngọc Hà' === $o['deliver_info']['deliver_district'], 'recipient ward -> deliver_district' );
t( 0.5 === $o['parcel_info']['parcel_weight'], '500g -> 0.5kg' );
t( 3 === $o['parcel_info']['parcel_item_quantity'], 'quantity mapped' );
t( 0 === $o['fulfillment_info']['cod_collection'] && ! isset( $o['fulfillment_info']['cod_amount'] ), 'COD off omits cod_amount' );
t( ! isset( $o['parcel_info']['express_insured_value'] ), 'no insured value when zero' );
t( ! isset( $o['parcel_info']['parcel_length'] ), 'no dimensions when absent' );

$cod = SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'is_cod' => true, 'cod_amount' => 150000 ) ) );
t( 1 === $cod['fulfillment_info']['cod_collection'] && 150000 === $cod['fulfillment_info']['cod_amount'], 'COD on sends integer cod_amount' );

$w15 = SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'weight_grams' => 15000 ) ) );
t( 15.0 === (float) $w15['parcel_info']['parcel_weight'], '15000g -> 15kg' );

t( 0 === SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'insured_value' => 2999999 ) ) )['fulfillment_info']['high_value_processing_collection'], 'insured 2,999,999 -> high_value 0' );
$hv = SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'insured_value' => 3000000 ) ) );
t( 1 === $hv['fulfillment_info']['high_value_processing_collection'] && 3000000 === $hv['parcel_info']['express_insured_value'], 'insured 3,000,000 -> high_value 1 + express_insured_value' );
t( 1 === SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'insured_value' => 9000000 ) ) )['fulfillment_info']['high_value_processing_collection'], 'insured above threshold -> high_value 1' );

$dim = SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'length_cm' => 20, 'width_cm' => 10, 'height_cm' => 5 ) ) );
t( 20.0 === $dim['parcel_info']['parcel_length'], 'valid dimensions included' );
t( ! isset( SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'length_cm' => 20, 'width_cm' => 10 ) ) )['parcel_info']['parcel_length'] ), 'partial dimensions omitted entirely' );
t( ! isset( SPX_Rate_Request_Mapper::map_order( array_merge( $base, array( 'length_cm' => 100, 'width_cm' => 100, 'height_cm' => 100 ) ) )['parcel_info']['parcel_length'] ), 'oversized dimensions omitted' );

/* ---- Service (fake transport + repo) ---- */
foreach ( array( 'SPX_TEST_BASE_URL' => 'https://test-stable.spx.vn/', 'SPX_TEST_APP_ID' => '1000490', 'SPX_TEST_APP_SECRET' => 'sec', 'SPX_TEST_USER_ID' => '277447632848309', 'SPX_TEST_USER_SECRET' => 'usec' ) as $k => $v ) { putenv( "$k=$v" ); }
$config = SPX_API_Config::for_test();

$imp = new SPX_Address_Import_Service();
function rw( $id, $ward, $dist, $prov, $del, $pick, $cod, $stat = 'Available' ) { return array( 'id' => (string) $id, 'ward' => $ward, 'district' => $dist, 'province' => $prov, 'delivery' => $del, 'pickup' => $pick, 'cod' => $cod, 'status' => $stat ); }
$ds = $imp->validate_and_build( array(
	rw( 1, 'W-OK', 'D1', 'P1', 'Y', 'Y', 'Y' ),
	rw( 2, 'W-NODEL', 'D2', 'P2', 'N', 'Y', 'Y' ),
	rw( 3, 'W-UNAVAIL', 'D2', 'P2', 'Y', 'Y', 'Y', 'Unavailable' ),
	rw( 4, 'W-NOCOD', 'D2', 'P2', 'Y', 'Y', 'N' ),
	rw( 5, 'W-NOPICK', 'D3', 'P3', 'Y', 'N', 'Y' ),
) );
$repo = SPX_Address_Repository::from_array( $ds['dataset'] );

// helper: codes for a ward id
function codes_for( $repo, $prov_name, $dist_name, $ward_id ) {
	$p = $repo->find_province_by_name( $prov_name ); $d = $repo->find_district_by_name( $p['code'], $dist_name );
	return array( 'province_code' => $p['code'], 'district_code' => $d['code'], 'ward_code' => (string) $ward_id );
}
$sender_codes = codes_for( $repo, 'P1', 'D1', 1 );
function ship( $sender_codes, $rcpt_codes, $extra = array() ) {
	return array_merge( array(
		'weight_grams' => 500, 'declared_value' => 0, 'is_cod' => false, 'cod_amount' => 0, 'service_type' => 1, 'collect_type' => 2,
		'items' => array( array( 'name' => 'Áo', 'quantity' => 1 ) ),
		'sender'    => array_merge( array( 'name' => 'Shop', 'phone' => '0900000000', 'address' => 'S1' ), $sender_codes ),
		'recipient' => array_merge( array( 'name' => 'Buyer', 'phone' => '0988888888', 'address' => 'S2' ), $rcpt_codes ),
	), $extra );
}
function svc( $config, $repo, &$fake ) { $fake = new SPX_Fake_Client(); return array( new SPX_Rate_Service( $config, $fake, $repo ), $fake ); }

// Delivery=N recipient rejected before any API call.
list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array() );
$r = $service->calculate( ship( $sender_codes, codes_for( $repo, 'P2', 'D2', 2 ) ) );
t( false === $r['success'] && 'recipient_no_delivery' === $r['error_code'] && '' === $fake->last_path, 'Delivery=N rejected locally (no API call)' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'recipient_area_unavailable' === $service->calculate( ship( $sender_codes, codes_for( $repo, 'P2', 'D2', 3 ) ) )['error_code'], 'Status Unavailable rejected' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'recipient_no_cod' === $service->calculate( ship( $sender_codes, codes_for( $repo, 'P2', 'D2', 4 ), array( 'is_cod' => true, 'cod_amount' => 100000 ) ) )['error_code'], 'COD order to COD=N area rejected' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'weight_exceeded' === $service->calculate( ship( $sender_codes, $sender_codes, array( 'weight_grams' => 16000 ) ) )['error_code'], 'weight > 15kg rejected' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'cod_exceeded' === $service->calculate( ship( $sender_codes, $sender_codes, array( 'is_cod' => true, 'cod_amount' => 25000000 ) ) )['error_code'], 'COD > 20m rejected' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'sender_no_pickup' === $service->calculate( ship( codes_for( $repo, 'P3', 'D3', 5 ), $sender_codes, array( 'collect_type' => 1 ) ) )['error_code'], 'pickup collect_type with sender Pick Up=N rejected' );

// Valid -> calls API, parses success with all fees.
list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array( array( 'order_id' => '1', 'estimated_shipping_fee' => 30000, 'basic_shipping_fee' => 25000, 'cod_service_fee' => 0, 'vat_fee' => 3000, 'edt_min' => 1, 'edt_max' => 2 ) ) );
$r = $service->calculate( ship( $sender_codes, $sender_codes ) );
t( true === $r['success'] && 30000 === $r['estimated_shipping_fee'], 'valid request returns estimated_shipping_fee' );
t( 0 === $r['cod_service_fee'] && 3000 === $r['vat_fee'], 'fee=0 preserved; present fees mapped' );
t( ! isset( $r['high_value_processing_fee'] ), 'missing optional fee omitted' );
t( 1 === $r['edt_min'] && '' === $r['currency'] && 'unconfirmed' === $r['fee_unit'], 'Gate A: EDT present; fee currency/unit NOT assumed to be VND' );

// Gate A: a decimal fee (e.g. VAT 1.56) is preserved raw, never scaled/rounded.
list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array( array( 'estimated_shipping_fee' => 21, 'vat_fee' => 1.56 ) ) );
$rd = $service->calculate( ship( $sender_codes, $sender_codes ) );
t( 21 === $rd['estimated_shipping_fee'] && 1.56 === $rd['vat_fee'] && '' === $rd['currency'], 'Gate A: raw decimal fee preserved, no VND default' );
t( '/open/api/v1/order/batch_check_order' === $fake->last_path, 'service calls the batch_check_order endpoint' );
t( isset( $fake->last_body['user_id'], $fake->last_body['orders'][0]['base_info'] ) && 1 === count( $fake->last_body['orders'] ), 'body carries user creds + exactly one order' );

// Failure parsing.
list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array( array( 'estimated_shipping_fee' => 'abc' ) ) );
t( 'invalid_fee' === $service->calculate( ship( $sender_codes, $sender_codes ) )['error_code'], 'non-numeric fee -> invalid_fee' );

list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array( array( 'estimated_shipping_fee' => -5 ) ) );
t( 'invalid_fee' === $service->calculate( ship( $sender_codes, $sender_codes ) )['error_code'], 'negative fee -> invalid_fee' );

list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = resp_ok( array(), array( array( 'ret_code' => 13050, 'message' => 'x', 'debug_msg' => 'secret-internal', 'order_id' => '1' ) ) );
$r = $service->calculate( ship( $sender_codes, $sender_codes ) );
t( false === $r['success'] && 'order_rejected' === $r['error_code'] && 13050 === $r['ret_code'], 'per-order fail_list -> order_rejected' );
t( false === strpos( json_encode( $r ), 'secret-internal' ), 'raw debug_msg not surfaced in failure' );

list( $service, $fake ) = svc( $config, $repo, $fake ); $fake->response = resp_ok( array() );
t( 'empty_orders' === $service->calculate( ship( $sender_codes, $sender_codes ) )['error_code'], 'empty orders -> empty_orders' );

list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = SPX_API_Response::from_http( 200, json_encode( array( 'ret_code' => 1007 ) ) );
$r = $service->calculate( ship( $sender_codes, $sender_codes ) );
t( 'api_error' === $r['error_code'] && false === $r['retryable'], 'top-level 1007 -> non-retryable api_error' );

list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = SPX_API_Response::from_http( 200, json_encode( array( 'ret_code' => 99004 ) ) );
t( true === $service->calculate( ship( $sender_codes, $sender_codes ) )['retryable'], '99004 -> retryable' );

list( $service, $fake ) = svc( $config, $repo, $fake );
$fake->response = SPX_API_Response::from_wp_error( new class { function get_error_message() { return 'timeout'; } } );
t( 'api_error' === $service->calculate( ship( $sender_codes, $sender_codes ) )['error_code'], 'transport error -> api_error' );
t( false === strpos( json_encode( $service->calculate( ship( $sender_codes, $sender_codes ) ) ), 'usec' ), 'result never contains user-secret' );

echo "\nAll SPX rate tests passed ({$GLOBALS['n']} assertions).\n";
