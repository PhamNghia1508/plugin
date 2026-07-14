<?php
declare( strict_types=1 );

/**
 * Cancel copy + user-facing jargon-zero (Phase 6Q).
 * Run: php tests/test-spx-cancel-jargon.php
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }

require_once dirname( __DIR__ ) . '/includes/admin/class-spx-admin-notice-presenter.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

// 1. Disabled cancel copy.
$m = SPX_Admin_Notice_Presenter::cancel_notice( 'gate_6f_b_required' );
check( $m && 'Chức năng hủy vận đơn chưa được kích hoạt.' === $m[1], 'disabled cancel copy' );

// 2. Status-ineligible copy.
$m = SPX_Admin_Notice_Presenter::cancel_notice( 'status_ineligible' );
check( $m && false !== strpos( $m[1], 'không còn đủ điều kiện hủy' ), 'ineligible cancel copy' );

// 3. Success copy makes clear no WooCommerce cancel / refund.
$m = SPX_Admin_Notice_Presenter::cancel_notice( 'succeeded' );
check( $m && 'success' === $m[0] && false !== strpos( $m[1], 'không được hoàn tiền' ), 'success copy states no refund' );

// 4. Unknown slug returns null (caller shows nothing, never a raw slug).
check( null === SPX_Admin_Notice_Presenter::cancel_notice( 'some_internal_thing' ), 'unknown slug → null' );

// 5. No user-facing message contains internal jargon.
$forbidden = array( 'Gate', 'gate_', 'fail_closed', 'shop_default', 'duplicate_unresolved', '6F-B', '6G-B', 'production_operation', 'error_code' );
foreach ( array( 'succeeded', 'already_cancelled', 'processing', 'unknown', 'gate_6f_b_required', 'status_ineligible', 'ineligible', 'invalid_tracking', 'environment_mismatch', 'locked', 'retry_blocked', 'cancel_failed', 'failed' ) as $slug ) {
	$m = SPX_Admin_Notice_Presenter::cancel_notice( $slug );
	check( is_array( $m ), "slug $slug is mapped" );
	if ( $m ) {
		foreach ( $forbidden as $bad ) {
			check( false === strpos( $m[1], $bad ), "no '$bad' in copy for $slug" );
		}
	}
}

if ( 0 === $failures ) { echo "OK test-spx-cancel-jargon\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
