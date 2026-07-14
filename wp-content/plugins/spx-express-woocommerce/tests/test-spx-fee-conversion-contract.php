<?php
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = null ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';

$n = 0;
function t( $condition, $message ) { global $n; $n++; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }

t( array( 'unconfirmed', 'experimental_thousand_vnd', 'verified_vnd', 'verified_thousand_vnd' ) === SPX_Fee_Conversion_Contract::modes(), 'all four conversion modes are explicit' );
$contract = new SPX_Fee_Conversion_Contract( 'experimental_thousand_vnd', 1000 );
$result = $contract->convert( 21 );
t( $result['success'] && '21000.00' === $result['converted_vnd'], 'raw 21 converts to decimal 21000.00' );
t( 21 === $result['raw'] && 1000 === $result['multiplier'], 'raw and experimental multiplier are preserved' );
t( 'experimental_thousand_vnd' === $result['fee_unit'] && 'VND_candidate' === $result['currency'], 'experimental unit and candidate currency stay explicit' );
t( '21560.00' === $contract->convert( 21.56 )['converted_vnd'], 'raw decimals are preserved through multiplication' );
t( $contract->convert( 5 )['success'] && $contract->convert( 2000 )['success'], 'plausibility boundaries are inclusive' );
t( ! $contract->convert( 4.999 )['success'] && 'suspicious_fee' === $contract->convert( 2000.001 )['error_code'], 'out-of-range candidates are rejected' );
t( ! ( new SPX_Fee_Conversion_Contract( 'experimental_thousand_vnd', 1 ) )->convert( 21 )['success'], 'experimental mode requires exact multiplier 1000' );
t( ! ( new SPX_Fee_Conversion_Contract( 'unconfirmed', 1000 ) )->convert( 21 )['success'], 'unconfirmed mode cannot create a customer charge' );
t( '21.56' === (string) $contract->safe_audit( 21.56 )['raw_estimated_shipping_fee'], 'safe audit preserves raw decimal' );
t( false === strpos( json_encode( $contract->safe_audit( 21 ) ), 'secret' ), 'safe audit has no secret-shaped fields' );
echo "All fee conversion contract tests passed ($n assertions).\n";

