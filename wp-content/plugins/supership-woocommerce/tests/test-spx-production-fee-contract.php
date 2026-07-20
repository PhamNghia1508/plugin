<?php
declare( strict_types=1 );

/**
 * Production full-VND fee contract preparation (Phase 6R). No network, no gate opened.
 * Run: php tests/test-spx-production-fee-contract.php
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }

require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

$prod = SPX_Fee_Conversion_Contract::for_production_full_vnd();

// 1. Full-VND: raw 21000 → 21000 (multiplier 1, no ×1000).
$r = $prod->convert( 21000 );
check( $r['success'] && '21000.00' === $r['converted_vnd'], 'production raw 21000 → 21000' );
check( 1 === $r['multiplier'] && 'VND' === $r['currency'], 'production multiplier 1, currency VND' );

// 2. raw 21500 → 21500.
check( '21500.00' === $prod->convert( 21500 )['converted_vnd'], 'production raw 21500 → 21500' );

// 3. 21000 never becomes 21,000,000.
check( '21000000.00' !== $prod->convert( 21000 )['converted_vnd'], 'production never multiplies by 1000' );

// 4. raw 21 on Production is abnormal (rejected), not turned into 21000.
$small = $prod->convert( 21 );
check( empty( $small['success'] ) && 'suspicious_fee' === $small['error_code'], 'production raw 21 rejected as abnormal' );

// 5. UAT experimental fixture still maps raw 21 → 21000 candidate (internal only).
$uat = SPX_Fee_Conversion_Contract::for_uat_experiment();
$u = $uat->convert( 21 );
check( $u['success'] && '21000.00' === $u['converted_vnd'] && 'VND_candidate' === $u['currency'], 'UAT raw 21 stays experimental candidate' );
check( $uat->is_experimental() && ! $uat->is_production_full_vnd(), 'UAT flagged experimental, not production' );

// 6. Production never uses the UAT ×1000 multiplier.
check( $prod->is_production_full_vnd() && ! $prod->is_experimental(), 'production is full-VND, not experimental' );

// 7. VAT is not double-counted: convert() multiplies only the single raw estimated fee.
//    (vat is carried as audit-only by the rate service; the contract never adds it.)
check( '21000.00' === $prod->convert( 21000 )['converted_vnd'], 'convert uses estimated fee only, no VAT added' );

// 8. Contract selection keeps Production CLOSED unless every precondition holds.
$closed_gates = array(
	array(), // nothing
	array( 'spx_environment' => 'production' ), // env only
	array( 'spx_environment' => 'production', 'account_verified' => true ), // no operation allowed
	array( 'spx_environment' => 'production', 'account_verified' => true, 'production_operation_allowed' => true ), // dynamic not enabled
	array( 'spx_environment' => 'test', 'account_verified' => true, 'production_operation_allowed' => true, 'production_dynamic_enabled' => true ), // sandbox env
);
foreach ( $closed_gates as $i => $gate ) {
	check( SPX_Fee_Conversion_Contract::select( $gate )->is_experimental(), "gate #$i stays on UAT experimental (production closed)" );
}

// 9. Only a fully-satisfied production gate selects the production contract (preparation only).
$open = SPX_Fee_Conversion_Contract::select( array(
	'spx_environment' => 'production', 'account_verified' => true,
	'production_operation_allowed' => true, 'production_dynamic_enabled' => true,
) );
check( $open->is_production_full_vnd(), 'fully-satisfied gate would select production full-VND' );

if ( 0 === $failures ) { echo "OK test-spx-production-fee-contract\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
