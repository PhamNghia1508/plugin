<?php
/**
 * PHASE 6G-A — Production enablement gate + verification marker.
 *
 * Pins the bootstrap Account-Verify policy and the fail-closed operation gate:
 * account_verify may run pre-marker only from an admin HTTPS request; every
 * other operation requires a valid marker whose fingerprint + host still match.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['spx_options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['spx_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['spx_options'][ $k ] = $v; return true; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
require_once dirname( __DIR__ ) . '/includes/production/class-spx-production-verification-store.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-production-gate.php';
$n = 0; function t( $c, $m ) { global $n; $n++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: $m\n"; }

$HOST = 'spx.vn';
$FP   = 'fp_current_abc123';
// A fully-satisfied non-bootstrap context (still needs a valid marker).
$ok = array( 'home_https' => true, 'site_https' => true, 'has_credentials' => true, 'host' => $HOST, 'fingerprint' => $FP, 'admin' => false, 'frontend' => false, 'cron' => false, 'is_ssl' => false );
// A fully-satisfied bootstrap (admin HTTPS) context.
$admin = array_merge( $ok, array( 'admin' => true, 'is_ssl' => true ) );

function reset_marker() { $GLOBALS['spx_options'] = array(); }

/* 1. account_verify allowed pre-marker from admin HTTPS. */
reset_marker();
t( SPX_Production_Gate::allows( 'account_verify', $admin ), '01 account_verify allowed pre-marker (admin+https+creds)' );
/* 2. blocked if not HTTPS request. */
t( ! SPX_Production_Gate::allows( 'account_verify', array_merge( $admin, array( 'is_ssl' => false ) ) ), '02 account_verify blocked without is_ssl' );
/* 3. blocked if credentials missing. */
t( ! SPX_Production_Gate::allows( 'account_verify', array_merge( $admin, array( 'has_credentials' => false ) ) ), '03 account_verify blocked without credentials' );
/* 4. blocked from frontend. */
t( ! SPX_Production_Gate::allows( 'account_verify', array_merge( $admin, array( 'admin' => false, 'frontend' => true ) ) ), '04 account_verify blocked from frontend' );
/* 4b. blocked from cron. */
t( ! SPX_Production_Gate::allows( 'account_verify', array_merge( $admin, array( 'admin' => false, 'cron' => true ) ) ), '04b account_verify blocked from cron' );
/* 2b. blocked if site not https. */
t( ! SPX_Production_Gate::allows( 'account_verify', array_merge( $admin, array( 'site_https' => false ) ) ), '02b account_verify blocked when site_url not https' );

/* 5-10 & 22. Operations blocked before verification. */
reset_marker();
foreach ( array( 'rate', 'create', 'search', 'tracking', 'label', 'cancel' ) as $i => $op ) {
	$d = SPX_Production_Gate::evaluate( $op, $ok );
	t( ! $d['allowed'] && 'verification_required' === $d['reason'], sprintf( '%02d %s blocked before verify (verification_required)', 5 + $i, $op ) );
}

/* 17. unknown operation blocked. */
t( ! SPX_Production_Gate::allows( 'delete_account', $ok ) && 'unknown_operation' === SPX_Production_Gate::evaluate( 'delete_account', $ok )['reason'], '17 unknown operation blocked' );

/* 11 & 16 & 23. Verify success -> marker -> gated ops allowed. */
reset_marker();
t( SPX_Production_Verification_Store::mark_verified( $FP, $HOST ), '11a mark_verified persists' );
$m = SPX_Production_Verification_Store::get();
t( true === $m['verified'] && 1 === $m['schema_version'] && $HOST === $m['host'] && $FP === $m['credential_fingerprint'] && '' !== $m['verified_at'], '11b marker shape correct' );
t( SPX_Production_Gate::allows( 'rate', $ok ) && SPX_Production_Gate::allows( 'create', $ok ) && SPX_Production_Gate::allows( 'tracking', $ok ), '16/23 valid marker allows rate/create/tracking' );

/* 12. Marker stores no secret material. */
$keys = array_keys( $m );
t( ! in_array( 'app_secret', $keys, true ) && ! in_array( 'user_secret', $keys, true ) && false === strpos( wp_json_or( $m ), 'user_secret' ), '12 marker contains no secret fields' );

/* 13. Credential change (different fingerprint) invalidates. */
t( ! SPX_Production_Gate::allows( 'rate', array_merge( $ok, array( 'fingerprint' => 'fp_changed_999' ) ) ), '13 credential change invalidates operations' );
t( 'verification_invalidated' === SPX_Production_Gate::evaluate( 'create', array_merge( $ok, array( 'fingerprint' => 'fp_changed_999' ) ) )['reason'], '15 fingerprint mismatch -> verification_invalidated' );
/* 14. Host change invalidates. */
t( ! SPX_Production_Gate::allows( 'rate', array_merge( $ok, array( 'host' => 'evil.example' ) ) ), '14 host change invalidates operations' );

/* 24-ish / 25-27. Failure/no-marker paths never allow gated ops. */
reset_marker();
t( ! SPX_Production_Gate::allows( 'rate', $ok ), '25 no marker after failed verify -> rate blocked' );
SPX_Production_Verification_Store::invalidate( 'credentials_changed' );
$mi = SPX_Production_Verification_Store::get();
t( false === $mi['verified'] && 'invalidated' === $mi['state'] && 'credentials_changed' === $mi['reason'], '13b invalidate() clears verified + records sanitized reason' );

/* 28. Old schema fails closed even if verified flag set. */
reset_marker();
$GLOBALS['spx_options'][ SPX_Production_Verification_Store::OPTION ] = array( 'schema_version' => 0, 'verified' => true, 'host' => $HOST, 'credential_fingerprint' => $FP, 'state' => 'verified' );
t( ! SPX_Production_Verification_Store::is_verified( $FP, $HOST ), '28 old schema marker fails closed' );
t( ! SPX_Production_Gate::allows( 'rate', $ok ), '28b old schema -> rate blocked' );

/* 03b. Missing host in context is blocked (no fallback). */
t( ! SPX_Production_Gate::allows( 'rate', array_merge( $ok, array( 'host' => '' ) ) ), '18 no host -> blocked (no sandbox fallback)' );

/* operation_for_path mapping. */
t( 'account_verify' === SPX_Production_Gate::operation_for_path( '/open/api/v1/account/verify' )
	&& 'rate' === SPX_Production_Gate::operation_for_path( '/open/api/v1/order/batch_check_order' )
	&& 'create' === SPX_Production_Gate::operation_for_path( '/open/api/v1/order/batch_create_order' )
	&& 'label' === SPX_Production_Gate::operation_for_path( '/open/api/v1/order/batch_get_shipping_label' )
	&& 'cancel' === SPX_Production_Gate::operation_for_path( '/open/api/v1/order/batch_cancel_order' )
	&& '' === SPX_Production_Gate::operation_for_path( '/open/api/v1/unknown' ), 'path->operation map correct' );

function wp_json_or( $v ) { return function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v ); }
echo "All production gate tests passed ($n assertions).\n";
