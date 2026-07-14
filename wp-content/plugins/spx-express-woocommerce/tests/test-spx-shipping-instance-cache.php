<?php
/** PHASE 6G-B0.6: matched-instance settings must invalidate Woo shipping cache. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function add_action() {}
function add_filter() {}
function wp_get_environment_type() { return 'local'; }

class WC_Shipping_Method {}
class SPX_Test_Zone_Method {
	public $id = 'spx_express';
	public $instance_id;
	public $enabled = 'yes';
	public $options;
	public function __construct( int $instance_id, array $options ) { $this->instance_id = $instance_id; $this->options = $options; }
	public function get_option( $key, $default = '' ) { return $this->options[ $key ] ?? $default; }
}
class SPX_Test_Zone {
	public $methods;
	public function __construct( array $methods ) { $this->methods = $methods; }
	public function get_shipping_methods( $enabled_only = false ) { return $this->methods; }
}
class WC_Shipping_Zones {
	public static $zone;
	public static function get_zone_matching_package( $package ) { return self::$zone; }
}

require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-shipping-method.php';

$count = 0;
$failed = 0;
function cache_assert( $condition, string $message ): void {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}

$base = array(
	'enabled' => 'yes',
	'base_cost' => '30000',
	'free_shipping_min_amount' => '0',
	'environment' => 'mock',
	'default_item_weight_grams' => '500',
);
$same_a = SPX_Shipping_Method::settings_cache_signature( 6, $base );
$same_b = SPX_Shipping_Method::settings_cache_signature( 6, $base );
cache_assert( $same_a === $same_b, 'identical instance settings produce a stable signature' );

foreach ( array(
	'instance' => array( 7, $base ),
	'base_cost' => array( 6, array_merge( $base, array( 'base_cost' => '31000' ) ) ),
	'threshold' => array( 6, array_merge( $base, array( 'free_shipping_min_amount' => '500000' ) ) ),
	'enabled' => array( 6, array_merge( $base, array( 'enabled' => 'no' ) ) ),
	'environment' => array( 6, array_merge( $base, array( 'environment' => 'test' ) ) ),
	'default_weight' => array( 6, array_merge( $base, array( 'default_item_weight_grams' => '750' ) ) ),
) as $name => $fixture ) {
	cache_assert( $same_a !== SPX_Shipping_Method::settings_cache_signature( $fixture[0], $fixture[1] ), $name . ' change invalidates the settings signature' );
}

$method = new SPX_Test_Zone_Method( 6, $base );
WC_Shipping_Zones::$zone = new SPX_Test_Zone( array( $method ) );
$package = array( 'contents' => array( array( 'product_id' => 10, 'quantity' => 1 ) ), 'destination' => array( 'country' => 'VN', 'state' => 'SG' ) );
$signed = SPX_Shipping_Method::add_package_cache_signature( array( $package ) );
cache_assert( isset( $signed[0]['spx_shipping_instance_signature'] ), 'package contains a matched-instance cache signature' );
$first = $signed[0]['spx_shipping_instance_signature'] ?? '';

$method->options['base_cost'] = '31000';
$signed_cost = SPX_Shipping_Method::add_package_cache_signature( array( $package ) );
cache_assert( $first !== ( $signed_cost[0]['spx_shipping_instance_signature'] ?? '' ), 'matched instance fee change invalidates Woo package cache' );

$method->instance_id = 7;
$signed_instance = SPX_Shipping_Method::add_package_cache_signature( array( $package ) );
cache_assert( ( $signed_cost[0]['spx_shipping_instance_signature'] ?? '' ) !== ( $signed_instance[0]['spx_shipping_instance_signature'] ?? '' ), 'matched zone instance change invalidates Woo package cache' );

if ( $failed ) { echo "SPX shipping instance cache RED/GREEN FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX shipping instance cache PASS ({$count} assertions).\n";
