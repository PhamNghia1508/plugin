<?php
/** Phase 6P canonical order-metabox contract. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$file = $root . '/includes/admin/class-spx-admin-order-metabox.php';
$count = 0;
$failed = 0;
function spx_box_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}
spx_box_assert( is_file( $file ), 'canonical SPX order metabox class exists' );
if ( is_file( $file ) ) {
	$source = file_get_contents( $file );
	foreach ( array( 'add_meta_box', 'wc_get_page_screen_id', "'shop_order'", "'normal'", 'spx-order-metabox', 'Tổng quan vận đơn', 'Địa chỉ giao hàng SPX', 'Phí vận chuyển', 'Trạng thái và hành trình', 'Hành động' ) as $needle ) {
		spx_box_assert( false !== strpos( $source, $needle ), 'metabox source contains ' . $needle );
	}
	spx_box_assert( 1 === substr_count( $source, "__( 'Mã vận đơn'" ), 'canonical metabox defines the tracking summary label once' );
	spx_box_assert( false !== strpos( $source, '<details class="spx-technical-details">' ), 'technical details use native details element' );
	spx_box_assert( false === strpos( $source, '<details class="spx-technical-details" open' ), 'technical details are closed by default' );
	spx_box_assert( false !== strpos( $source, 'SPX_Payment_Resolver::resolve' ), 'metabox consumes the canonical payment resolver' );
	spx_box_assert( false !== strpos( $source, 'render_payment' ), 'metabox renders canonical payment readiness' );
	foreach ( array( 'Phương thức thanh toán', 'Đã thanh toán', 'Tiền SPX cần thu hộ', 'Lý do khóa' ) as $payment_copy ) {
		spx_box_assert( false !== strpos( $source, $payment_copy ), 'metabox payment copy contains ' . $payment_copy );
	}
}
$legacy_renderers = array(
	'includes/class-spx-order-actions.php',
	'includes/admin/class-spx-admin-address.php',
	'includes/admin/class-spx-admin-rate.php',
	'includes/admin/class-spx-admin-shipment.php',
	'includes/admin/class-spx-admin-label.php',
	'includes/admin/class-spx-admin-cancel.php',
	'includes/admin/class-spx-admin-status-mapping.php',
);
foreach ( $legacy_renderers as $relative ) {
	$source = file_get_contents( $root . '/' . $relative );
	spx_box_assert( false === strpos( $source, "add_action( 'woocommerce_admin_order_data_after_shipping_address'") && false === strpos( $source, "add_action('woocommerce_admin_order_data_after_shipping_address'"), basename( $relative ) . ' no longer hooks the Shipping column' );
}
if ( $failed ) { echo "SPX admin order metabox RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX admin order metabox PASS ({$count} assertions).\n";
