<?php
declare( strict_types=1 );

/**
 * SPX limit + Rate/Create consistency tests for the canonical parcel model (Phase 6Q).
 * Run: php tests/test-spx-parcel-validation.php
 */

define( 'ABSPATH', __DIR__ );

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

// 1. Weight over Create limit (17 kg): 9 kg × 2 = 18 kg → over.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 9, 'weight_unit' => 'kg', 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 2 ) ),
	array( 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'weight over create 17kg invalid' );
check( in_array( 'weight_over_limit', $r->error_codes(), true ), 'weight_over_limit code' );
check( 'Kiện hàng vượt giới hạn cân nặng SPX.' === $r->first_error_message(), 'weight limit VN message' );

// 2. Same parcel is fine under 17 but the Rate cap is 15: 16 kg valid for Create, invalid for Rate.
$lines16 = array( array( 'weight' => 8, 'weight_unit' => 'kg', 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 2 ) );
$create = SPX_Parcel_Builder::from_lines( $lines16, array( 'max_weight_kg' => 17.0 ) );
$rate   = SPX_Parcel_Builder::from_lines( $lines16, array( 'max_weight_kg' => 15.0 ) );
check( $create->is_valid(), '16kg valid for create' );
check( ! $rate->is_valid(), '16kg invalid for rate cap 15' );

// 3. Single dimension over 60 cm.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 61, 'width' => 10, 'height' => 10, 'quantity' => 1 ) ),
	array( 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'length 61 over 60 invalid' );
check( in_array( 'dimension_over_limit', $r->error_codes(), true ), 'dimension_over_limit code' );

// 4. Sum of three dimensions over 180 (each ≤60): 60+60+61 fails single first, use 60/60/61? use 55/60/70→70>60.
//    Craft a case where each ≤60 but sum >180: impossible (max sum with each ≤60 is 180). So sum-limit is
//    only reachable via stacked height. 50+50+90(height stacked) → height 90 >60 triggers single. Use qty to
//    stack height within 60: h=20×3=60 (ok), plus l=60,w=60 → sum=180 ok. Push l=60,w=61 → single trips.
//    The sum rule therefore mainly guards the exact 180 boundary; verify boundary passes.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 60, 'width' => 60, 'height' => 60, 'quantity' => 1 ) ),
	array( 'max_weight_kg' => 17.0 )
);
check( $r->is_valid(), '60/60/60 sum=180 boundary valid' );

// 5. Empty / all-virtual cart → no shippable items.
$r = SPX_Parcel_Builder::from_lines(
	array( array( 'weight' => 5, 'weight_unit' => 'kg', 'virtual' => true, 'quantity' => 1 ) ),
	array( 'max_weight_kg' => 17.0 )
);
check( ! $r->is_valid(), 'all virtual invalid' );
check( in_array( 'no_shippable_items', $r->error_codes(), true ), 'no_shippable_items code' );

// 6. Rate and Create build IDENTICAL geometry from identical input (the core consistency guarantee).
$lines = array(
	array( 'weight' => 500, 'weight_unit' => 'g', 'length' => 12, 'width' => 9, 'height' => 6, 'dimension_unit' => 'cm', 'quantity' => 2 ),
	array( 'weight' => 1, 'weight_unit' => 'kg', 'length' => 30, 'width' => 5, 'height' => 4, 'dimension_unit' => 'cm', 'quantity' => 1 ),
);
$rate   = SPX_Parcel_Builder::from_lines( $lines, array( 'max_weight_kg' => 15.0 ) );
$create = SPX_Parcel_Builder::from_lines( $lines, array( 'max_weight_kg' => 17.0 ) );
check( $rate->weight_kg() === $create->weight_kg(), 'rate/create same weight' );
check( $rate->length_cm() === $create->length_cm(), 'rate/create same length' );
check( $rate->width_cm() === $create->width_cm(), 'rate/create same width' );
check( $rate->height_cm() === $create->height_cm(), 'rate/create same height' );
check( $rate->policy_version() === $create->policy_version(), 'rate/create same policy version' );

// 7. policy_version present in snapshot + cache parts (cache invalidation guarantee).
check( isset( $create->cache_parts()['policy_version'] ), 'cache_parts has policy_version' );
check( isset( $create->to_snapshot()['_spx_parcel_policy_version'] ), 'snapshot has policy_version' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-validation\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
