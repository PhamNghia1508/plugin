<?php
/**
 * Lightweight, network-free tests for SPX client order id, create mapping, and
 * the create state machine (created/duplicate/failed/unknown).
 *   docker run --rm -v "$PWD":/app -w /app php:7.4-cli \
 *     php wp-content/plugins/spx-express-woocommerce/tests/test-spx-shipment.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $t, $d = null ) { return $t; }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function is_wp_error( $t ) { return is_object( $t ) && method_exists( $t, 'get_error_message' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_current_blog_id() { return 1; }

$api = dirname( __DIR__ ) . '/includes/api/';
$adr = dirname( __DIR__ ) . '/includes/address/';
foreach ( array( 'class-spx-api-error-mapper', 'class-spx-api-response', 'class-spx-api-config', 'class-spx-request-signer', 'interface-spx-http-client', 'class-spx-client-order-id', 'class-spx-create-request-mapper', 'class-spx-shipment-service' ) as $f ) { require_once $api . $f . '.php'; }
foreach ( array( 'class-spx-address-normalizer', 'class-spx-address-import-service', 'class-spx-address-repository' ) as $f ) { require_once $adr . $f . '.php'; }

$GLOBALS['n'] = 0;
function t( $c, $m ) { $GLOBALS['n']++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: {$m}\n"; }
class SPX_Fake_Client implements SPX_Http_Client_Interface {
	public $response; public $last_path = ''; public $last_body = array(); public $calls = 0;
	public function request( string $path, array $payload ): SPX_API_Response { $this->last_path = $path; $this->last_body = $payload; $this->calls++; return $this->response; }
}
function resp( $ret, $data = null ) { $b = array( 'ret_code' => $ret ); if ( null !== $data ) { $b['data'] = $data; } return SPX_API_Response::from_http( 200, json_encode( $b ) ); }

/* ---- Client order id ---- */
t( 'WC-1-T-55' === SPX_Client_Order_ID::build( 1, SPX_API_Config::TEST_ENV, 55 ), 'client id deterministic format' );
t( SPX_Client_Order_ID::build( 1, 'test', 55 ) === SPX_Client_Order_ID::build( 1, 'test', 55 ), 'same order -> same id' );
t( SPX_Client_Order_ID::build( 1, 'test', 55 ) !== SPX_Client_Order_ID::build( 1, 'test', 56 ), 'different orders -> different id' );
t( SPX_Client_Order_ID::build( 1, 'test', 55 ) !== SPX_Client_Order_ID::build( 1, 'production', 55 ), 'test vs production differ' );
$big = SPX_Client_Order_ID::build( 999999, 'test', PHP_INT_MAX );
t( strlen( $big ) <= 32, 'client id stays within 32 chars even for large ids' );
t( false === strpos( SPX_Client_Order_ID::build( 1, 'test', 55 ), '@' ), 'client id has no PII' );
t( (bool) preg_match( '/^[A-Z0-9-]+$/', SPX_Client_Order_ID::build( 1, 'test', 55 ) ), 'client id uses a restricted non-PII character set' );

/* ---- Create mapper ---- */
$ship = array(
	'client_order_id' => 'WC-1-T-55', 'service_type' => 1, 'payment_role' => 1, 'collect_type' => 2,
	'is_cod' => true, 'cod_amount' => 300000, 'insured_value' => 0, 'weight_grams' => 600,
	'parcel_item_name' => 'Áo', 'parcel_item_quantity' => 2,
	'sender'    => array( 'province' => 'HCM', 'district' => 'Q1', 'ward' => 'P1', 'name' => 'Shop', 'phone' => '0900000000', 'address' => 'S1' ),
	'recipient' => array( 'province' => 'HCM', 'district' => 'Q1', 'ward' => 'P2', 'name' => 'Buyer', 'phone' => '0988888888', 'address' => 'S2', 'instruction' => 'gọi trước' ),
);
$o = SPX_Create_Request_Mapper::map_order( $ship );
t( 'WC-1-T-55' === $o['order_id'], 'mapper sets order_id' );
t( 'Shop' === $o['sender_info']['sender_name'] && '0900000000' === $o['sender_info']['sender_phone'], 'sender name/phone mapped' );
t( 'Buyer' === $o['deliver_info']['deliver_name'] && 'P2' === $o['deliver_info']['deliver_district'], 'recipient name + ward mapped' );
t( 'gọi trước' === $o['deliver_info']['deliver_instruction'], 'deliver instruction included when present' );
t( 1 === $o['fulfillment_info']['payment_role'] && 2 === $o['fulfillment_info']['collect_type'], 'payment_role + collect_type' );
t( 1 === $o['fulfillment_info']['cod_collection'] && 300000 === $o['fulfillment_info']['cod_amount'], 'COD on integer amount' );
t( 0.6 === $o['parcel_info']['parcel_weight'] && 2 === $o['parcel_info']['parcel_item_quantity'], 'weight kg + quantity' );
t( ! isset( $o['fulfillment_info']['pickup_time'] ), 'no pickup_time for collect_type=2' );
$pk = SPX_Create_Request_Mapper::map_order( array_merge( $ship, array( 'collect_type' => 1, 'pickup_time' => 1700000000 ) ) );
t( 1700000000 === $pk['fulfillment_info']['pickup_time'], 'pickup_time included for collect_type=1' );
$hv = SPX_Create_Request_Mapper::map_order( array_merge( $ship, array( 'insured_value' => 3000000 ) ) );
t( 1 === $hv['fulfillment_info']['high_value_processing_collection'] && 3000000 === $hv['parcel_info']['express_insured_value'], 'high-value rule' );
t( 17.0 === (float) SPX_Create_Request_Mapper::map_order( array_merge( $ship, array( 'weight_grams' => 17000 ) ) )['parcel_info']['parcel_weight'], '17kg maps' );

/* ---- Shipment service (fake transport + repo) ---- */
foreach ( array( 'SPX_TEST_BASE_URL' => 'https://test-stable.spx.vn/', 'SPX_TEST_APP_ID' => '1000490', 'SPX_TEST_APP_SECRET' => 's', 'SPX_TEST_USER_ID' => '277447632848309', 'SPX_TEST_USER_SECRET' => 'usec' ) as $k => $v ) { putenv( "$k=$v" ); }
$config = SPX_API_Config::for_test();
$imp = new SPX_Address_Import_Service();
function rw( $id, $ward, $dist, $prov, $del, $pick, $cod, $st = 'Available' ) { return array( 'id' => (string) $id, 'ward' => $ward, 'district' => $dist, 'province' => $prov, 'delivery' => $del, 'pickup' => $pick, 'cod' => $cod, 'status' => $st ); }
$repo = SPX_Address_Repository::from_array( $imp->validate_and_build( array( rw( 1, 'W1', 'D1', 'P1', 'Y', 'Y', 'Y' ), rw( 2, 'W2', 'D1', 'P1', 'Y', 'Y', 'Y' ) ) )['dataset'] );
$p = $repo->find_province_by_name( 'P1' ); $d = $repo->find_district_by_name( $p['code'], 'D1' );
$codes = array( 'province_code' => $p['code'], 'district_code' => $d['code'] );
function full_ship( $codes, $extra = array() ) {
	return array_merge( array(
		'client_order_id' => 'WC-1-T-55', 'weight_grams' => 600, 'declared_value' => 0, 'is_cod' => false, 'cod_amount' => 0,
		'payment_method' => 'bacs', 'payment_state' => 'paid', 'ready_for_shipment' => true, 'payment_block_reason' => '',
		'service_type' => 1, 'payment_role' => 1, 'collect_type' => 2, 'items' => array( array( 'name' => 'Áo', 'quantity' => 2 ) ),
		'sender'    => array_merge( array( 'name' => 'Shop', 'phone' => '0900000000', 'address' => 'S1', 'ward_code' => '1' ), $codes ),
		'recipient' => array_merge( array( 'name' => 'Buyer', 'phone' => '0988888888', 'address' => 'S2', 'ward_code' => '2' ), $codes ),
	), $extra );
}
function mk( $config, $repo, &$fake ) { $fake = new SPX_Fake_Client(); return new SPX_Shipment_Service( $config, $fake, $repo ); }

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array( array( 'order_id' => 'WC-1-T-55', 'tracking_no' => 'SPXVN123456789', 'tracking_link' => 'https://test.spx.vn/track?SPXVN123456789', 'estimated_shipping_fee' => 21, 'vat_fee' => 1.56 ) ), 'fail_list' => array() ) );
$r = $svc->create( full_ship( $codes ) );
t( true === $r['success'] && 'created' === $r['state'] && 'SPXVN123456789' === $r['tracking_no'], 'valid create -> created with real tracking' );
t( 1 === $fake->calls, 'create calls the API exactly once' );
t( 'https://test.spx.vn/track?SPXVN123456789' === $r['tracking_link'], 'https tracking link kept' );
t( 'unconfirmed' === $r['fees']['fee_unit'] && 21 === $r['fees']['estimated_shipping_fee'] && 1.56 === $r['fees']['vat_fee'], 'fees raw + unit unconfirmed' );
t( false === strpos( json_encode( $r ), 'usec' ), 'create result has no user-secret' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array( array( 'order_id' => 'WC-1-T-55', 'tracking_no' => 'SPXMOCK-20260101-ABCDEF' ) ), 'fail_list' => array() ) );
t( 'unknown' === $svc->create( full_ship( $codes ) )['state'], 'SPXMOCK tracking from API -> unknown (not accepted)' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array( array( 'order_id' => 'WC-1-T-55', 'ret_code' => 13103, 'message' => 'dup' ) ) ) );
$r = $svc->create( full_ship( $codes ) );
t( 'duplicate' === $r['state'] && 13103 === $r['ret_code'], '13103 -> duplicate state' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array( array( 'order_id' => 'WC-1-T-55', 'ret_code' => 13101, 'message' => 'x', 'debug_msg' => 'secret-internal' ) ) ) );
$r = $svc->create( full_ship( $codes ) );
t( 'failed' === $r['state'] && false === strpos( json_encode( $r ), 'secret-internal' ), 'per-order fail -> failed, no debug_msg' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array( array( 'order_id' => 'WC-1-T-55' ) ), 'fail_list' => array() ) );
t( 'unknown' === $svc->create( full_ship( $codes ) )['state'], 'missing tracking_no -> unknown' );

$svc = mk( $config, $repo, $fake );
$fake->response = SPX_API_Response::from_wp_error( new class { function get_error_message() { return 'timeout'; } } );
t( 'unknown' === $svc->create( full_ship( $codes ) )['state'], 'transport error -> unknown (may have created)' );

$svc = mk( $config, $repo, $fake );
$fake->response = SPX_API_Response::from_http( 500, '' );
t( 'unknown' === $svc->create( full_ship( $codes ) )['state'], 'HTTP 500 -> unknown' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array() ) );
$r = $svc->create( full_ship( $codes, array( 'weight_grams' => 18000 ) ) );
t( 'failed' === $r['state'] && 0 === $fake->calls, 'weight>17kg -> failed locally (no API call, not unknown)' );

$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array() ) );
$r = $svc->create( full_ship( $codes, array( 'client_order_id' => 'WC/PII?55' ) ) );
t( 'failed' === $r['state'] && 0 === $fake->calls, 'invalid client id characters -> failed locally without an API call' );

/* ---- Canonical payment guard: direct Create path must fail before mapper/HTTP. ---- */
$svc = mk( $config, $repo, $fake );
$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array() ) );
$r = $svc->create( full_ship( $codes, array(
	'payment_method' => '', 'payment_state' => 'missing', 'ready_for_shipment' => false,
	'payment_block_reason' => 'payment_method_missing', 'is_cod' => false, 'cod_amount' => null,
) ) );
t( 'failed' === $r['state'] && 'payment_method_missing' === $r['error_code'] && 0 === $fake->calls, 'direct Create blocks missing payment method before HTTP' );

$mapper_blocked = false;
try {
	SPX_Create_Request_Mapper::map_order( array_merge( $ship, array( 'is_cod' => true, 'cod_amount' => null ) ) );
} catch ( InvalidArgumentException $e ) {
	$mapper_blocked = true;
}
t( $mapper_blocked, 'Create mapper never falls back missing COD amount to zero' );

echo "\nAll SPX shipment tests passed ({$GLOBALS['n']} assertions).\n";
