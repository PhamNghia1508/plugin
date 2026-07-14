<?php
/** Phase 6P settings architecture contract. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$file = $root . '/includes/admin/class-spx-admin-settings-page.php';
$count = 0;
$failed = 0;
function spx_tabs_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}

spx_tabs_assert( is_file( $file ), 'canonical settings-page class exists' );
if ( is_file( $file ) ) {
	define( 'ABSPATH', __DIR__ );
	function __( $text, $domain = null ) { return $text; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	require_once $file;
	$tabs = SPX_Admin_Settings_Page::tabs();
	spx_tabs_assert( array( 'overview', 'connection', 'sender', 'addresses', 'rates', 'tracking', 'statuses', 'tools' ) === array_keys( $tabs ), 'settings expose the eight approved tabs in order' );
	spx_tabs_assert( 'tracking' === SPX_Admin_Settings_Page::active_tab( 'tracking' ), 'allowlisted tab remains active' );
	spx_tabs_assert( 'overview' === SPX_Admin_Settings_Page::active_tab( '../../connection' ), 'invalid tab falls back to overview' );
	spx_tabs_assert( 'overview' === SPX_Admin_Settings_Page::active_tab( array( 'tracking' ) ), 'non-scalar tab falls back to overview' );
	$source = file_get_contents( $file );
	foreach ( array( 'nav-tab-wrapper', 'nav-tab', 'spx-admin-page', 'spx-admin-grid', 'Cấu hình kết nối', 'Cấu hình phí', 'Công cụ hệ thống' ) as $needle ) {
		spx_tabs_assert( false !== strpos( $source, $needle ), 'settings source contains ' . $needle );
	}
	spx_tabs_assert( false === strpos( $source, 'include( $_GET' ) && false === strpos( $source, 'require( $_GET' ), 'tab query never controls a file include' );
}

$redirect_sources = file_get_contents( $root . '/includes/admin/class-spx-admin-address.php' )
	. file_get_contents( $root . '/includes/admin/class-spx-admin-tracking.php' )
	. file_get_contents( $root . '/includes/admin/class-spx-admin-status-mapping.php' )
	. file_get_contents( $root . '/includes/admin/class-spx-admin-production.php' );
foreach ( array( "'sender'", "'addresses'", "'tracking'", "'statuses'", "'connection'" ) as $tab ) {
	spx_tabs_assert( false !== strpos( $redirect_sources, $tab ), 'save redirect preserves fixed tab ' . $tab );
}
if ( $failed ) { echo "SPX admin settings tabs RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX admin settings tabs PASS ({$count} assertions).\n";

