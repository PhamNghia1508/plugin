<?php
declare( strict_types=1 );

/**
 * Dynamic-rate cache key changes when parcel geometry, missing-data policy,
 * default parcel settings, or the parcel policy version change (Phase 6Q).
 * Run: php tests/test-spx-parcel-cache-invalidation.php
 */

define( 'ABSPATH', __DIR__ );
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }

require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-cache.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

$cache = new SPX_Checkout_Rate_Cache();
$base = array(
	'environment' => 'test', 'sender_location' => 'p1|d1|w1', 'recipient_location' => 'p2|d2|w2',
	'service_type' => 1, 'weight_grams' => 1200, 'dimensions' => '20|10|10',
	'cart_hash' => 'cart-a', 'package_hash' => 'pkg-a', 'is_cod' => false, 'cod_amount' => 0,
	'contract_version' => '6J-X-1', 'unit_mode' => 'experimental_thousand_vnd',
	'parcel_policy' => 'fail_closed', 'parcel_policy_version' => '6Q-1',
	'parcel_defaults' => '{"default_weight_kg":0,"default_length_cm":0,"default_width_cm":0,"default_height_cm":0}',
);
$key = $cache->make_key( $base );

check( $key !== $cache->make_key( array_merge( $base, array( 'dimensions' => '30|10|10' ) ) ), 'changed dimensions change key' );
check( $key !== $cache->make_key( array_merge( $base, array( 'weight_grams' => 1500 ) ) ), 'changed weight changes key' );
check( $key !== $cache->make_key( array_merge( $base, array( 'parcel_policy' => 'shop_default' ) ) ), 'changed missing-data policy changes key' );
check( $key !== $cache->make_key( array_merge( $base, array( 'parcel_policy_version' => '6Q-2' ) ) ), 'changed policy version changes key' );
check( $key !== $cache->make_key( array_merge( $base, array( 'parcel_defaults' => '{"default_weight_kg":0.5,"default_length_cm":20,"default_width_cm":15,"default_height_cm":10}' ) ) ), 'changed default parcel settings change key' );
check( $key === $cache->make_key( $base ), 'identical input yields identical key' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-cache-invalidation\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
