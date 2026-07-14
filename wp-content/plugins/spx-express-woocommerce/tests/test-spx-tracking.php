<?php
/**
 * Lightweight, network-free tests for SPX status mapping + tracking service.
 *   docker run --rm -v "$PWD":/app -w /app php:7.4-cli \
 *     php wp-content/plugins/spx-express-woocommerce/tests/test-spx-tracking.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $t, $d = null ) { return $t; }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function is_wp_error( $t ) { return is_object( $t ) && method_exists( $t, 'get_error_message' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

$api = dirname( __DIR__ ) . '/includes/api/';
foreach ( array( 'class-spx-api-error-mapper', 'class-spx-api-response', 'class-spx-api-config', 'class-spx-request-signer', 'interface-spx-http-client', 'class-spx-tracking-status-mapper', 'class-spx-tracking-service' ) as $f ) { require_once $api . $f . '.php'; }

$GLOBALS['n'] = 0;
function t( $c, $m ) { $GLOBALS['n']++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: {$m}\n"; }
class SPX_Fake_Client implements SPX_Http_Client_Interface {
	public $response; public $last_path = ''; public $last_body = array();
	public function request( string $path, array $payload ): SPX_API_Response { $this->last_path = $path; $this->last_body = $payload; return $this->response; }
}
function resp( $ret, $data = null ) { $b = array( 'ret_code' => $ret ); if ( null !== $data ) { $b['data'] = $data; } return SPX_API_Response::from_http( 200, json_encode( $b ) ); }

/* ---- Status mapper: all 12 codes ---- */
$expect = array(
	'1001' => array( 'pending_pickup', false ), '2001' => array( 'in_transit', false ), '2006' => array( 'out_for_delivery', false ),
	'3001' => array( 'on_hold', false ), '4001' => array( 'delivered', true ), '5001' => array( 'pickup_failed', false ),
	'5002' => array( 'damaged', true ), '5003' => array( 'lost', true ), '6001' => array( 'returning', false ),
	'6002' => array( 'return_failed', false ), '6003' => array( 'returned', true ), '7001' => array( 'cancelled', true ),
);
foreach ( $expect as $code => $e ) {
	$m = SPX_Tracking_Status_Mapper::map( $code );
	t( $e[0] === $m['internal_status'] && $e[1] === $m['terminal'] && '' !== $m['customer_label'], "status $code -> {$e[0]} (terminal=" . ( $e[1] ? '1' : '0' ) . ')' );
}
$u = SPX_Tracking_Status_Mapper::map( '9999' );
t( 'unknown' === $u['internal_status'] && false === $u['terminal'], 'unknown status code -> safe non-terminal fallback' );
t( 'Đã hủy vận đơn' === SPX_Tracking_Status_Mapper::map( '7001' )['customer_label'], 'cancelled status uses the approved Vietnamese customer label' );

/* ---- Tracking service ---- */
foreach ( array( 'SPX_TEST_BASE_URL' => 'https://test-stable.spx.vn/', 'SPX_TEST_APP_ID' => '1000490', 'SPX_TEST_APP_SECRET' => 's', 'SPX_TEST_USER_ID' => '277447632848309', 'SPX_TEST_USER_SECRET' => 'usec' ) as $k => $v ) { putenv( "$k=$v" ); }
$config = SPX_API_Config::for_test();
$fake = new SPX_Fake_Client();
$svc = new SPX_Tracking_Service( $config, $fake );

$fake->response = resp( 0, array( 'orders' => array( array(
	'tracking_no' => 'SPXVN123456789', 'order_id' => 'WC-1-T-55', 'status' => 'In Transit', 'status_code' => '2001',
	'tracking_link' => 'https://test.spx.vn/track?SPXVN123456789',
	'deliver_info' => array( 'deliver_phone' => '0988888888', 'deliver_name' => 'Buyer' ),
	'routes' => array( array( 'status_code' => '1001', 'status' => 'Pending Pickup', 'message' => 'người gửi chuẩn bị', 'timestamp' => 1700000000 ), array( 'status_code' => '2001', 'timestamp' => 1700003600 ) ),
) ), 'fail_list' => array() ) );
$r = $svc->get_by_tracking_number( 'SPXVN123456789' );
t( true === $r['success'] && true === $r['found'] && 'in_transit' === $r['internal_status'], 'lookup by tracking number -> mapped status' );
t( '/open/api/v1/order/batch_search_order' === $fake->last_path && isset( $fake->last_body['tracking_no_list'] ), 'tracking uses batch_search_order with tracking_no_list' );
t( 'Đang vận chuyển' === $r['customer_label'], 'Vietnamese customer label mapped' );
t( 2 === count( $r['routes'] ) && ! isset( $r['routes'][0]['message'] ), 'routes carry status_code+timestamp but NOT raw message' );
t( false === strpos( json_encode( $r ), '0988888888' ) && false === strpos( json_encode( $r ), 'Buyer' ), 'tracking result contains no recipient PII' );

$r2 = $svc->get_by_client_order_id( 'WC-1-T-55' );
t( isset( $fake->last_body['order_id_list'] ), 'lookup by client order id uses order_id_list' );

$fake->response = resp( 0, array( 'orders' => array(), 'fail_list' => array( array( 'ret_code' => 13251, 'message' => 'not found', 'tracking_no' => 'SPXVN123456789' ) ) ) );
$nf = $svc->get_by_tracking_number( 'SPXVN123456789' );
t( true === $nf['success'] && false === $nf['found'] && 13251 === $nf['ret_code'], '13251 -> found=false (eventual consistency)' );

$fake->response = resp( 0, array( 'orders' => array( array( 'tracking_no' => 'SPXVN123456', 'status_code' => '4001' ) ), 'fail_list' => array() ) );
t( true === $svc->get_by_tracking_number( 'SPXVN123456' )['terminal'], 'delivered (4001) is terminal' );

$fake->response = SPX_API_Response::from_wp_error( new class { function get_error_message() { return 'timeout'; } } );
t( false === $svc->get_by_tracking_number( 'SPXVN123456' )['success'], 'transport error -> failure (no crash)' );

$fake->response = resp( 0, array( 'orders' => array( array( 'tracking_no' => 'SPXMOCK-20260101-ABCDEF', 'order_id' => 'WC-1-T-55', 'status_code' => '1001' ) ), 'fail_list' => array() ) );
t( false === $svc->get_by_client_order_id( 'WC-1-T-55' )['found'], 'tracking lookup never accepts SPXMOCK as a real shipment' );

echo "\nAll SPX tracking tests passed ({$GLOBALS['n']} assertions).\n";
