<?php
declare( strict_types=1 );

/**
 * Offline parcel fixtures A–J (Phase 6Q). No network, no SPX API.
 * Run: php tests/test-spx-parcel-fixtures.php
 *
 * Variation-own-dims (C) and variation→parent fallback (D) at the WooCommerce
 * extraction layer are additionally covered by test-spx-create-parcel-integration
 * and test-spx-dynamic-checkout-rate; here they are represented as resolved lines.
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }

require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-validation-result.php';
require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-builder.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }
function line( $w, $l = 0, $wd = 0, $h = 0, $q = 1, $v = false ) {
	return array( 'weight' => $w, 'weight_unit' => 'g', 'length' => $l, 'width' => $wd, 'height' => $h, 'dimension_unit' => 'cm', 'quantity' => $q, 'virtual' => $v );
}
$create = array( 'max_weight_kg' => 17.0, 'policy' => 'fail_closed' );

// A. Full weight + dimensions.
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 20, 10, 5 ) ), $create );
check( $r->is_valid() && 0.6 === $r->weight_kg() && $r->has_dimensions() && 'product' === $r->source(), 'A full data valid' );

// B. Quantity > 1.
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 20, 10, 5, 3 ) ), $create );
check( $r->is_valid() && 1.8 === $r->weight_kg() && 15.0 === $r->height_cm(), 'B quantity aggregates weight + height' );

// C. Variation with its own dimensions (resolved line).
$r = SPX_Parcel_Builder::from_lines( array( line( 400, 15, 12, 8 ) ), $create );
check( $r->is_valid() && 15.0 === $r->length_cm(), 'C variation own dimensions' );

// D. Variation inheriting parent dimensions (resolved line, same shape).
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 20, 10, 5 ) ), $create );
check( $r->is_valid() && 20.0 === $r->length_cm(), 'D variation→parent dimensions' );

// E. Missing data + fail_closed → no SPX method.
$r = SPX_Parcel_Builder::from_lines( array( line( null, 0, 0, 0 ) ), $create );
check( ! $r->is_valid() && in_array( 'missing_weight', $r->error_codes(), true ), 'E missing data fail_closed blocks' );

// F. Missing data + shop_default → valid via defaults.
$r = SPX_Parcel_Builder::from_lines( array( line( null, 0, 0, 0 ) ), array(
	'max_weight_kg' => 17.0, 'policy' => 'shop_default',
	'default_weight_kg' => 0.5, 'default_length_cm' => 20, 'default_width_cm' => 15, 'default_height_cm' => 10,
) );
check( $r->is_valid() && 0.5 === $r->weight_kg() && 'shop_default' === $r->source(), 'F missing data shop_default fills' );

// G. Virtual + physical → virtual excluded.
$r = SPX_Parcel_Builder::from_lines( array( line( 700, 10, 10, 10 ), line( 5000, 0, 0, 0, 1, true ) ), $create );
check( $r->is_valid() && 0.7 === $r->weight_kg() && 1 === $r->item_count(), 'G virtual excluded' );

// H. Over weight limit.
$r = SPX_Parcel_Builder::from_lines( array( line( 9000, 10, 10, 10, 2 ) ), $create );
check( ! $r->is_valid() && in_array( 'weight_over_limit', $r->error_codes(), true ), 'H over weight blocked' );

// I. Single dimension over 60 cm.
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 61, 10, 10 ) ), $create );
check( ! $r->is_valid() && in_array( 'dimension_over_limit', $r->error_codes(), true ), 'I single dimension over limit' );

// J. Total dimensions over 180 (each ≤60 via stacked height).
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 60, 60, 20, 4 ) ), $create ); // height 20*4=80 → single-dim trips first
check( ! $r->is_valid(), 'J stacked height triggers a dimension limit' );
// Boundary: exactly 180 is allowed.
$r = SPX_Parcel_Builder::from_lines( array( line( 600, 60, 60, 60 ) ), $create );
check( $r->is_valid(), 'J boundary 60/60/60 = 180 allowed' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-fixtures\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
