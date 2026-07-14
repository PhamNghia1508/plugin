<?php
define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
require_once dirname( __DIR__ ) . '/includes/production/class-spx-production-state.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-production-readiness.php';
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
$base = array( 'credentials' => true, 'verification' => false, 'sender' => true, 'dataset' => true, 'https' => true, 'hpos' => true, 'scheduler' => true, 'compatibility' => true );
$result = SPX_Production_Readiness::evaluate( $base );
e( ! $result['ready'] && in_array( 'verification', $result['failed'], true ), 'missing verification blocks readiness' );
e( in_array( 'webhook_not_configured', $result['warnings'], true ), 'webhook warning is explicit' );
e( in_array( 'fixed_rate_active', $result['warnings'], true ), 'fixed-rate warning is explicit' );
e( 'readiness_pending' === SPX_Production_State::offline_transition( 'disabled', 'readiness_pending' ), 'offline phase may enter readiness pending' );
e( 'readiness_pending' === SPX_Production_State::offline_transition( 'readiness_pending', 'verified' ), 'offline phase cannot fabricate verified' );
e( ! SPX_Production_State::verification_matches( 'old', 'new' ), 'credential change invalidates verification' );
foreach ( array( 'credentials', 'verification', 'sender', 'dataset', 'https', 'hpos', 'scheduler', 'compatibility' ) as $blocker ) {
	$checks = array_fill_keys( array_keys( $base ), true ); $checks[$blocker] = false; $one = SPX_Production_Readiness::evaluate( $checks );
	e( ! $one['ready'] && array( $blocker ) === $one['failed'], $blocker . ' independently blocks readiness' );
}
$all = SPX_Production_Readiness::evaluate( array_fill_keys( array_keys( $base ), true ) );
e( $all['ready'], 'all checklist inputs can pass only when verification is supplied' );
e( 'readiness_pending' === SPX_Production_State::offline_transition( 'readiness_pending', 'enabled' ), 'offline phase cannot enable production' );
echo "Production readiness tests passed.\n";
