<?php
declare( strict_types=1 );

/**
 * Shipment readiness surfaces canonical parcel errors in Vietnamese (Phase 6Q).
 * Run: php tests/test-spx-parcel-readiness.php
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }

require_once dirname( __DIR__ ) . '/includes/class-spx-shipment-readiness.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

// Fully valid sender + recipient so only the parcel section decides readiness.
$valid_ctx = array(
	'sender' => array(
		'sender_name' => 'Shop', 'sender_phone' => '0900000000', 'sender_detail_address' => 'Addr',
		'sender_province_code' => 'p', 'sender_district_code' => 'd', 'sender_ward_code' => 'w',
	),
	'recipient' => array(
		'name' => 'Buyer', 'phone' => '0980000000', 'address' => 'Addr',
		'province_code' => 'p2', 'district_code' => 'd2', 'ward_code' => 'w2',
	),
	'payment' => array(
		'payment_method' => 'bacs', 'is_paid' => true, 'payment_state' => 'paid',
		'cod_amount' => 0, 'ready_for_shipment' => true, 'reason' => '',
	),
);

// 1. Canonical parcel errors (Vietnamese) surface and block readiness.
$ctx = $valid_ctx;
$ctx['parcel'] = array( 'weight_grams' => 0, 'item_quantity' => 1, 'errors' => array(
	array( 'code' => 'missing_weight', 'message' => 'Sản phẩm chưa có cân nặng.' ),
) );
$r = SPX_Shipment_Readiness::evaluate( $ctx );
check( ! $r['ready'], 'parcel error blocks readiness' );
$messages = array_map( function ( $e ) { return $e['message']; }, $r['errors'] );
check( in_array( 'Sản phẩm chưa có cân nặng.', $messages, true ), 'Vietnamese parcel reason surfaced' );
// No internal slug leaks into a message.
foreach ( $messages as $m ) { check( false === strpos( $m, 'missing_weight' ), 'no raw slug in message' ); }

// 2. Missing-dimension Vietnamese reason.
$ctx = $valid_ctx;
$ctx['parcel'] = array( 'weight_grams' => 500, 'item_quantity' => 1, 'errors' => array(
	array( 'code' => 'missing_dimensions', 'message' => 'Sản phẩm chưa có đầy đủ kích thước.' ),
) );
$r = SPX_Shipment_Readiness::evaluate( $ctx );
check( ! $r['ready'], 'missing dimensions blocks readiness' );

// 3. Valid parcel (no errors, weight ok) → ready.
$ctx = $valid_ctx;
$ctx['parcel'] = array( 'weight_grams' => 1200, 'item_quantity' => 2, 'errors' => array() );
$r = SPX_Shipment_Readiness::evaluate( $ctx );
check( $r['ready'], 'valid parcel is ready' );

// 4. Fallback path (no errors array) still enforces weight sanity in Vietnamese.
$ctx = $valid_ctx;
$ctx['parcel'] = array( 'weight_grams' => 0, 'item_quantity' => 1 );
$r = SPX_Shipment_Readiness::evaluate( $ctx );
$messages = array_map( function ( $e ) { return $e['message']; }, $r['errors'] );
check( ! $r['ready'] && in_array( 'Sản phẩm chưa có cân nặng.', $messages, true ), 'fallback weight reason VN' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-readiness\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
