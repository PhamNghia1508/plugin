<?php
/** Phase 6P pure presentation action-visibility contract. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$file = $root . '/includes/admin/class-spx-admin-order-metabox.php';
$count = 0;
$failed = 0;
function spx_action_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}
spx_action_assert( is_file( $file ), 'order metabox action presenter exists' );
if ( is_file( $file ) ) {
	define( 'ABSPATH', __DIR__ );
	require_once $file;
	$local = SPX_Admin_Order_Metabox::action_visibility( array( 'wp_environment' => 'local', 'production_requested' => false, 'sandbox_configured' => true, 'ready' => true, 'has_tracking' => false, 'has_client_id' => false, 'production_verified' => false, 'label_eligible' => false, 'cancel_enabled' => false, 'cancel_eligible' => false ) );
	spx_action_assert( $local['mock_create'] && $local['sandbox_create'] && ! $local['production_create'], 'local development isolates mock and enables one Sandbox Create' );
	$shop = SPX_Admin_Order_Metabox::action_visibility( array( 'wp_environment' => 'production', 'production_requested' => true, 'sandbox_configured' => false, 'ready' => true, 'has_tracking' => false, 'has_client_id' => false, 'production_verified' => false, 'label_eligible' => false, 'cancel_enabled' => false, 'cancel_eligible' => false ) );
	spx_action_assert( ! $shop['mock_create'] && ! $shop['sandbox_create'] && ! $shop['production_create'] && $shop['production_locked'], 'normal shop hides mock and locks Production Create before verification' );
	$payment_blocked = SPX_Admin_Order_Metabox::action_visibility( array( 'wp_environment' => 'production', 'production_requested' => true, 'sandbox_configured' => false, 'ready' => false, 'has_tracking' => false, 'has_client_id' => false, 'production_verified' => true, 'label_eligible' => false, 'cancel_enabled' => false, 'cancel_eligible' => false ) );
	spx_action_assert( ! $payment_blocked['production_create'], 'verified Production Create remains hidden when canonical readiness is blocked' );
	$shipment = SPX_Admin_Order_Metabox::action_visibility( array( 'wp_environment' => 'local', 'sandbox_configured' => true, 'ready' => true, 'has_tracking' => true, 'has_client_id' => true, 'production_verified' => false, 'label_eligible' => true, 'cancel_enabled' => false, 'cancel_eligible' => true ) );
	spx_action_assert( ! $shipment['sandbox_create'] && $shipment['label'] && $shipment['sync'] && ! $shipment['cancel'], 'existing shipment shows label/sync but hides gated cancel and duplicate create' );
	$cancel = SPX_Admin_Order_Metabox::action_visibility( array( 'wp_environment' => 'local', 'sandbox_configured' => true, 'ready' => true, 'has_tracking' => true, 'has_client_id' => true, 'production_verified' => false, 'label_eligible' => true, 'cancel_enabled' => true, 'cancel_eligible' => true ) );
	spx_action_assert( $cancel['cancel'], 'cancel appears only when execution and canonical eligibility are both true' );
}
$contracts = file_get_contents( $root . '/includes/admin/class-spx-admin-label.php' ) . file_get_contents( $root . '/includes/admin/class-spx-admin-cancel.php' ) . file_get_contents( $root . '/includes/admin/class-spx-admin-shipment.php' );
foreach ( array( 'admin_post_spx_get_label', 'admin_post_spx_cancel_shipment', 'spx_get_label', 'spx_cancel_shipment', 'edit_shop_orders', 'manage_woocommerce', 'check_admin_referer' ) as $contract ) {
	spx_action_assert( false !== strpos( $contracts, $contract ), 'existing action security contract remains: ' . $contract );
}
if ( $failed ) { echo "SPX admin action visibility RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX admin action visibility PASS ({$count} assertions).\n";
