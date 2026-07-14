<?php
declare( strict_types=1 );

/**
 * Default-parcel settings validation + configured getters (Phase 6Q).
 * Run: php tests/test-spx-parcel-settings.php
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }

require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-validation-result.php';
require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-builder.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

// 1. Negatives clamp to 0; over-limits clamp to caps; decimals preserved.
$out = SPX_Parcel_Builder::sanitize_defaults_input( array(
	'parcel_weight_grams' => '-50', 'parcel_length_cm' => '80', 'parcel_width_cm' => '15.5', 'parcel_height_cm' => '10', 'parcel_policy' => 'shop_default',
) );
check( 0.0 === $out['defaults']['weight_grams'], 'negative weight clamps to 0' );
check( 60.0 === $out['defaults']['length_cm'], 'over-limit length clamps to 60' );
check( 15.5 === $out['defaults']['width_cm'], 'decimal width preserved' );
check( 'shop_default' === $out['policy'], 'shop_default policy accepted' );

// 2. Unknown policy normalizes to fail_closed (safe default).
$out = SPX_Parcel_Builder::sanitize_defaults_input( array( 'parcel_policy' => 'anything' ) );
check( 'fail_closed' === $out['policy'], 'unknown policy → fail_closed' );

// 3. Weight cap at 17000 g.
$out = SPX_Parcel_Builder::sanitize_defaults_input( array( 'parcel_weight_grams' => '99999' ) );
check( 17000.0 === $out['defaults']['weight_grams'], 'weight caps at 17000' );

// 4. configured_policy / configured_defaults read the stored options.
$GLOBALS['opts'][ SPX_Parcel_Builder::OPTION_POLICY ] = 'shop_default';
$GLOBALS['opts'][ SPX_Parcel_Builder::OPTION_DEFAULTS ] = array( 'weight_grams' => 500, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10 );
check( 'shop_default' === SPX_Parcel_Builder::configured_policy(), 'configured_policy reads option' );
$d = SPX_Parcel_Builder::configured_defaults();
check( 0.5 === $d['default_weight_kg'] && 20.0 === $d['default_length_cm'], 'configured_defaults converts g→kg and reads cm' );

// 5. Default policy when unset is fail_closed.
$GLOBALS['opts'] = array();
check( 'fail_closed' === SPX_Parcel_Builder::configured_policy(), 'unset policy defaults fail_closed' );

if ( 0 === $failures ) { echo "OK test-spx-parcel-settings\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
