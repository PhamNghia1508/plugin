<?php
/** Phase 6P accessibility/static semantic contract. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$settings = $root . '/includes/admin/class-spx-admin-settings-page.php';
$metabox = $root . '/includes/admin/class-spx-admin-order-metabox.php';
$source = ( is_file( $settings ) ? file_get_contents( $settings ) : '' ) . ( is_file( $metabox ) ? file_get_contents( $metabox ) : '' ) . file_get_contents( $root . '/includes/admin/class-spx-admin-address.php' ) . file_get_contents( $root . '/includes/admin/class-spx-admin-status-mapping.php' );
$count = 0;
$failed = 0;
function spx_a11y_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}
foreach ( array( '<label for="', '<fieldset', '<legend', '<details', '<summary>', 'role="status"', 'aria-disabled="true"' ) as $semantic ) {
	spx_a11y_assert( false !== strpos( $source, $semantic ), 'accessible semantic exists: ' . $semantic );
}
spx_a11y_assert( false === strpos( is_file( $settings ) ? file_get_contents( $settings ) : '', 'style="' ), 'settings renderer has no inline style attributes' );
spx_a11y_assert( false === strpos( is_file( $metabox ) ? file_get_contents( $metabox ) : '', 'style="' ), 'metabox renderer has no inline style attributes' );
$css = file_get_contents( $root . '/assets/css/admin.css' );
foreach ( array( '.spx-admin-page', '.spx-admin-tabs', '.spx-admin-grid', '.spx-admin-card', '.spx-order-metabox', '.spx-technical-details', '@media' ) as $selector ) {
	spx_a11y_assert( false !== strpos( $css, $selector ), 'scoped admin CSS contains ' . $selector );
}
spx_a11y_assert( ! preg_match( '/(^|\})\s*(?:input|select|button|table|\.wrap|\.woocommerce)\s*\{/m', $css ), 'admin CSS does not style global controls or WooCommerce' );
if ( $failed ) { echo "SPX admin UI accessibility RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX admin UI accessibility PASS ({$count} assertions).\n";

