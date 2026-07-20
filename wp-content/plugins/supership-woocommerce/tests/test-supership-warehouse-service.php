<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }
function wp_cache_get( $key, $group = '' ) { return false; }
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return true; }
function wp_cache_flush() { return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $expiration = 0 ) { return true; }

require_once dirname( __DIR__ ) . '/includes/api/class-supership-api-response.php';
require_once dirname( __DIR__ ) . '/includes/api/class-supership-api-error-mapper.php';
require_once dirname( __DIR__ ) . '/includes/api/interface-supership-http-client.php';
require_once dirname( __DIR__ ) . '/includes/address/class-supership-address-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/address/class-supership-address-cache.php';
require_once dirname( __DIR__ ) . '/includes/address/class-supership-address-repository.php';
require_once dirname( __DIR__ ) . '/includes/warehouse/class-supership-warehouse-service.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

/**
 * Records every call and returns a scripted response per endpoint path, so
 * tests can assert the EXACT endpoint + field names SuperShip_Warehouse_Service
 * sends. This is what would have caught, as a regression test, the
 * /v1/partner/pickup/* vs /v1/partner/warehouses* endpoint bug and the
 * pickup_code vs code field-name bug found during manual doc review.
 */
final class Fake_Http_Client implements SuperShip_Http_Client_Interface {
	public $calls = array();
	private $responses;

	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	public function get( string $path, array $query_params = array() ): SuperShip_API_Response {
		$this->calls[] = array( 'method' => 'GET', 'path' => $path, 'body' => $query_params );
		return $this->respond( $path );
	}

	public function post( string $path, array $body = array() ): SuperShip_API_Response {
		$this->calls[] = array( 'method' => 'POST', 'path' => $path, 'body' => $body );
		return $this->respond( $path );
	}

	private function respond( string $path ): SuperShip_API_Response {
		if ( ! isset( $this->responses[ $path ] ) ) {
			throw new RuntimeException( "Fake_Http_Client has no scripted response for path: {$path}" );
		}
		return SuperShip_API_Response::from_http( 200, json_encode( $this->responses[ $path ] ) );
	}
}

// Fixtures mirror the real example payloads on docs.developers.supership.vn
// (areas.html / warehouses.html, re-verified live 2026-07-19).
$responses = array(
	'/v1/partner/areas/province'      => array(
		'status'  => 'Success',
		'results' => array( array( 'code' => '79', 'name' => 'Thành phố Hồ Chí Minh' ) ),
	),
	'/v1/partner/areas/district'      => array(
		'status'  => 'Success',
		'results' => array( array( 'code' => '766', 'name' => 'Quận Tân Bình', 'province' => 'Thành phố Hồ Chí Minh' ) ),
	),
	'/v1/partner/areas/commune'       => array(
		'status'  => 'Success',
		'results' => array( array( 'code' => '27037', 'name' => 'Phường Hiệp Tân', 'district' => 'Quận Tân Bình', 'province' => 'Thành phố Hồ Chí Minh' ) ),
	),
	'/v1/partner/warehouses'          => array(
		'status'  => 'Success',
		'results' => array(
			array(
				'code'         => 'WCQ1I07047',
				'name'         => 'Kho Hoàng Nam',
				'address'      => '47 Huỳnh Văn Bánh',
				'province'     => 'Thành phố Hồ Chí Minh',
				'district'     => 'Quận Tân Bình',
				'commune'      => 'Phường Hiệp Tân',
				'status'       => '1',
				'status_name'  => 'Hoạt Động',
				'primary'      => '1',
				'primary_name' => 'Kho Mặc Định',
			),
		),
	),
	'/v1/partner/warehouses/create'   => array(
		'status'  => 'Success',
		'results' => array( 'code' => 'WLKGT07050', 'name' => 'Kho HBT', 'status' => '1', 'status_name' => 'Hoạt Động', 'primary' => '1', 'primary_name' => 'Kho Mặc Định' ),
	),
	'/v1/partner/warehouses/update'   => array(
		'status'  => 'Success',
		'results' => array( 'code' => 'WLKGT07050', 'diff' => array( 'name' => 'Kho Ba Đình' ) ),
	),
);

$client       = new Fake_Http_Client( $responses );
$cache        = new SuperShip_Address_Cache();
$address_repo = new SuperShip_Address_Repository( $client, $cache );
$warehouse    = new SuperShip_Warehouse_Service( $client, $address_repo );

// get_warehouses(): must call the real /v1/partner/warehouses endpoint (not /pickup/list)
// and expose the identifier as 'code' (not 'pickup_code').
$result = $warehouse->get_warehouses();
expect_same( true, $result['success'], 'get_warehouses() must succeed against a well-formed response' );
expect_same( 'WCQ1I07047', $result['warehouses'][0]['code'], 'Warehouse identifier must be exposed as "code"' );
expect_same( '/v1/partner/warehouses', $client->calls[0]['path'], 'get_warehouses() must call /v1/partner/warehouses, not /v1/partner/pickup/list' );

// create_warehouse(): must call /v1/partner/warehouses/create, resolve province/district/commune
// via the Areas API (even from loosely-typed input), and default primary/contact sensibly.
$create_result = $warehouse->create_warehouse(
	array(
		'name'     => 'Kho HM',
		'phone'    => '0989999888',
		'address'  => '47 Lê Lợi',
		'province' => 'Hồ Chí Minh', // Loosely-typed - must still resolve via fuzzy match.
		'district' => 'Tân Bình',
		'commune'  => 'Hiệp Tân',
	)
);
expect_same( true, $create_result['success'], 'create_warehouse() must succeed when required fields are present' );

$create_call = null;
foreach ( $client->calls as $call ) {
	if ( '/v1/partner/warehouses/create' === $call['path'] ) {
		$create_call = $call;
	}
}
expect_same( true, null !== $create_call, 'create_warehouse() must call /v1/partner/warehouses/create' );
expect_same( 'Thành phố Hồ Chí Minh', $create_call['body']['province'], 'create_warehouse() must send the resolved canonical province name' );
expect_same( '2', $create_call['body']['primary'], 'create_warehouse() must default primary to "2" (normal) when not specified' );
expect_same( 'Kho HM', $create_call['body']['contact'], 'create_warehouse() must default contact to the warehouse name when not specified (contact is required by SuperShip)' );

// update_warehouse(): must call /v1/partner/warehouses/update with 'code' (not 'pickup_code'),
// and must never send province/district/commune (SuperShip docs: not updatable this way).
$update_result = $warehouse->update_warehouse( 'WLKGT07050', array( 'name' => 'Kho Ba Đình', 'province' => 'should be ignored' ) );
expect_same( true, $update_result['success'], 'update_warehouse() must succeed with valid fields' );

$update_call = null;
foreach ( $client->calls as $call ) {
	if ( '/v1/partner/warehouses/update' === $call['path'] ) {
		$update_call = $call;
	}
}
expect_same( 'WLKGT07050', $update_call['body']['code'], 'update_warehouse() must send "code" (not "pickup_code")' );
expect_same( false, isset( $update_call['body']['province'] ), 'update_warehouse() must never send province - SuperShip does not support changing it this way' );

// delete_warehouse(): SuperShip has no delete endpoint - must fail clearly instead of
// silently calling a made-up URL.
$calls_before   = count( $client->calls );
$delete_result  = $warehouse->delete_warehouse( 'WLKGT07050' );
expect_same( false, $delete_result['success'], 'delete_warehouse() must report failure - SuperShip has no delete API' );
expect_same( $calls_before, count( $client->calls ), 'delete_warehouse() must not call any API endpoint - none exists' );

echo "All SuperShip_Warehouse_Service tests passed.\n";
