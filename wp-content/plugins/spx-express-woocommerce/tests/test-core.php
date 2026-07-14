<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_textarea_field( $text ) { return sanitize_text_field( $text ); }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_woocommerce_currency() { return 'VND'; }
function wp_generate_password() { return 'Ab12Cd'; }
function current_time() { return '2026-07-12 00:00:00'; }
function get_bloginfo() { return 'Test shop'; }
function wc_format_decimal( $value ) { return is_numeric( $value ) ? (string) $value : ''; }
function wc_get_weight( $value, $to_unit, $from_unit = '' ) {
	$from_unit = $from_unit ?: 'kg';
	$grams = array( 'kg' => 1000, 'g' => 1, 'lbs' => 453.59237, 'oz' => 28.349523125 );
	return (float) $value * $grams[ $from_unit ] / $grams[ $to_unit ];
}
function WC() { return new class() { public $countries; public function __construct() { $this->countries = new class() { public function get_base_address() { return 'Shop'; } public function get_base_state() { return 'SG'; } }; } }; }

class WC_Shipping_Method {}
class WC_Order {}

require_once dirname( __DIR__ ) . '/includes/providers/interface-spx-shipping-provider.php';
require_once dirname( __DIR__ ) . '/includes/providers/class-spx-mock-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-shipping-method.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-logger.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

expect_same( 0.0, SPX_Shipping_Method::normalize_amount( '-100' ), 'Negative amount must clamp to zero' );
expect_same( 0.0, SPX_Shipping_Method::normalize_amount( 'invalid' ), 'Invalid amount must become zero' );
expect_same( false, SPX_Shipping_Method::qualifies_for_free_shipping( 500000.0, 0.0 ), 'Zero threshold must stay disabled' );
expect_same( true, SPX_Shipping_Method::qualifies_for_free_shipping( 500000.0, 500000.0 ), 'Threshold must be inclusive' );

$provider = new SPX_Mock_Provider();
$result = $provider->create_shipment( array( 'recipient' => array( 'address' => 'A', 'phone' => '1' ), 'items' => array( array( 'quantity' => 1 ) ), 'weight_grams' => 500 ) );
expect_same( 1, preg_match( '/^SPXMOCK-\d{8}-[A-Z0-9]{6}$/', $result['tracking_number'] ), 'Tracking format must be stable' );

$redacted = SPX_Logger::redact( 'Authorization: Bearer abc123 api_secret=secret phone=0901234567 "token":"xyz"' );
expect_same( false, false !== strpos( $redacted, 'abc123' ), 'Bearer credential must be removed' );
expect_same( false, false !== strpos( $redacted, '0901234567' ), 'Full phone number must be removed' );
expect_same( false, false !== strpos( $redacted, '=secret' ), 'Secret value must be removed' );

echo "All lightweight tests passed.\n";
