<?php
declare( strict_types=1 );

/**
 * Unit tests for the canonical parcel builder (Phase 6Q).
 * Run: php tests/test-spx-parcel-builder.php
 *
 * Pure logic only — no WordPress, no network, no SPX API.
 */

define( 'ABSPATH', __DIR__ );

// Minimal WP/WC shims mirroring tests/test-core.php.
function __( $text, $domain = '' ) { return $text; }
function wc_get_weight( $value, $to_unit, $from_unit = '' ) {
	$from_unit = $from_unit ?: 'kg';
	$grams = array( 'kg' => 1000, 'g' => 1, 'lbs' => 453.59237, 'oz' => 28.349523125 );
	return (float) $value * $grams[ $from_unit ] / $grams[ $to_unit ];
}

require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-validation-result.php';
require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-builder.php';

$failures = 0;
function check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) { $failures++; fwrite( STDERR, "FAIL: $message\n" ); }
}
function approx( float $a, float $b, float $eps = 0.0005 ): bool { return abs( $a - $b ) <= $eps; }

$create = array( 'max_weight_kg' => 17.0 );

// 1. Simple kg, single item.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 1.2, 'weight_unit' => 'kg', 'length' => 10, 'width' => 8, 'height' => 5, 'dimension_unit' => 'cm', 'quantity' => 1 ) ),
	$create
);
check( $r->is_valid(), 'kg single item valid' );
check( approx( $r->weight_kg(), 1.2 ), 'kg weight' );
check( 'product' === $r->source(), 'source product' );

// 2. Grams input, quantity 3 → 500 g × 3 = 1.5 kg (the brief's worked example).
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 500, 'weight_unit' => 'g', 'length' => 10, 'width' => 10, 'height' => 4, 'dimension_unit' => 'cm', 'quantity' => 3 ) ),
	$create
);
check( approx( $r->weight_kg(), 1.5 ), 'g×qty = 1.5kg (got ' . $r->weight_kg() . ')' );
check( 3 === $r->item_count(), 'item count 3' );

// 3. lbs and oz conversion.
$r = SPX_Parcel_Builder::from_lines( array( array( 'weight' => 1, 'weight_unit' => 'lbs', 'length' => 5, 'width' => 5, 'height' => 5, 'quantity' => 1 ) ), $create );
check( approx( $r->weight_kg(), 0.454, 0.001 ), 'lbs→kg' );
$r = SPX_Parcel_Builder::from_lines( array( array( 'weight' => 16, 'weight_unit' => 'oz', 'length' => 5, 'width' => 5, 'height' => 5, 'quantity' => 1 ) ), $create );
check( approx( $r->weight_kg(), 0.454, 0.001 ), 'oz→kg' );

// 4. Dimension unit conversion (mm, m, in).
$r = SPX_Parcel_Builder::from_lines( array( array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 100, 'width' => 80, 'height' => 50, 'dimension_unit' => 'mm', 'quantity' => 1 ) ), $create );
check( approx( $r->length_cm(), 10 ) && approx( $r->width_cm(), 8 ) && approx( $r->height_cm(), 5 ), 'mm→cm' );
$r = SPX_Parcel_Builder::from_lines( array( array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 0.1, 'width' => 0.08, 'height' => 0.05, 'dimension_unit' => 'm', 'quantity' => 1 ) ), $create );
check( approx( $r->length_cm(), 10 ), 'm→cm' );
$r = SPX_Parcel_Builder::from_lines( array( array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 1, 'width' => 1, 'height' => 1, 'dimension_unit' => 'in', 'quantity' => 1 ) ), $create );
check( approx( $r->length_cm(), 2.54 ), 'in→cm' );

// 5. Deterministic multi-item dims: length=max, width=max, height=Σ(h·qty).
$r = SPX_Parcel_Builder::from_lines(
	array(
		array( 'weight' => 0.5, 'weight_unit' => 'kg', 'length' => 10, 'width' => 20, 'height' => 5, 'quantity' => 2 ),
		array( 'weight' => 0.5, 'weight_unit' => 'kg', 'length' => 30, 'width' => 8, 'height' => 4, 'quantity' => 1 ),
	),
	$create
);
check( approx( $r->length_cm(), 30 ), 'multi length=max' );
check( approx( $r->width_cm(), 20 ), 'multi width=max' );
check( approx( $r->height_cm(), 14 ), 'multi height=Σ(h·qty)=5*2+4=14 (got ' . $r->height_cm() . ')' );

// 6. Virtual/downloadable excluded from parcel.
$r = SPX_Parcel_Builder::from_lines(
	array(
		array( 'weight' => 2, 'weight_unit' => 'kg', 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1 ),
		array( 'weight' => 5, 'weight_unit' => 'kg', 'virtual' => true, 'quantity' => 1 ),
	),
	$create
);
check( approx( $r->weight_kg(), 2 ), 'virtual excluded from weight' );
check( 1 === $r->item_count(), 'virtual excluded from count' );

// 7. Missing weight + fail_closed → invalid with Vietnamese reason, no 0 fabrication.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => null, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1 ) ),
	array( 'policy' => 'fail_closed', 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'missing weight fail_closed invalid' );
check( in_array( 'missing_weight', $r->error_codes(), true ), 'missing_weight code' );
check( 'Sản phẩm chưa có cân nặng.' === $r->first_error_message(), 'missing weight VN message' );

// 8. Missing weight + shop_default (with default weight) → valid via default.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => null, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1 ) ),
	array( 'policy' => 'shop_default', 'default_weight_kg' => 0.5, 'max_weight_kg' => 17.0 )
);
check( $r->is_valid(), 'shop_default fills missing weight' );
check( approx( $r->weight_kg(), 0.5 ), 'default weight applied' );
// Product supplied dimensions but weight came from the shop default → mixed source.
check( 'mixed' === $r->source(), 'source mixed (product dims + default weight)' );
check( in_array( 'weight_from_default', $r->warnings(), true ), 'weight_from_default warning' );

// 8b. Pure shop_default: both weight and dims defaulted.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => null, 'quantity' => 1 ) ),
	array( 'policy' => 'shop_default', 'default_weight_kg' => 0.5, 'default_length_cm' => 20, 'default_width_cm' => 15, 'default_height_cm' => 10, 'max_weight_kg' => 17.0 )
);
check( $r->is_valid() && 'shop_default' === $r->source(), 'pure shop_default source' );

// 9. Missing dimensions + fail_closed (require_dimensions default true) → invalid.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 1, 'weight_unit' => 'kg', 'quantity' => 1 ) ),
	array( 'policy' => 'fail_closed', 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'missing dims fail_closed invalid' );
check( in_array( 'missing_dimensions', $r->error_codes(), true ), 'missing_dimensions code' );

// 10. Missing dimensions + shop default dims → valid via default dims.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 1, 'weight_unit' => 'kg', 'quantity' => 1 ) ),
	array( 'policy' => 'shop_default', 'default_length_cm' => 20, 'default_width_cm' => 15, 'default_height_cm' => 10, 'max_weight_kg' => 17.0 )
);
check( $r->is_valid(), 'default dims applied' );
check( approx( $r->length_cm(), 20 ) && approx( $r->height_cm(), 10 ), 'default dim values' );

// 11. Negative/zero weight never becomes a silent 0 parcel.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 0, 'weight_unit' => 'kg', 'length' => 5, 'width' => 5, 'height' => 5, 'quantity' => 1 ) ),
	array( 'policy' => 'fail_closed', 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'zero weight invalid' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-builder\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
