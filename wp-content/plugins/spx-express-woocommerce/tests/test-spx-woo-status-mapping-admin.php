<?php
/** Static security/UI contract for status-mapping administration. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$file = $root . '/includes/admin/class-spx-admin-status-mapping.php';
if ( ! is_file( $file ) ) { throw new RuntimeException( 'RED: status mapping admin component does not exist yet.' ); }
$code = file_get_contents( $file );
$n = 0;
function a_ok( $condition, $message ) { global $n; ++$n; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }
a_ok( false !== strpos( $code, "current_user_can( 'manage_woocommerce' )" ), 'settings require manage_woocommerce' );
a_ok( false !== strpos( $code, "'POST' !== ( \$_SERVER['REQUEST_METHOD']" ), 'settings save is POST-only' );
a_ok( false !== strpos( $code, 'check_admin_referer' ), 'settings save requires nonce verification' );
a_ok( false !== strpos( $code, 'SPX_Woo_Status_Mapping_Policy::sanitize' ), 'settings use policy allowlist sanitizer' );
a_ok( false === strpos( $code, "update_status( \$_POST" ), 'request cannot select an arbitrary Woo status' );
a_ok( false === strpos( $code, 'wc_create_refund' ) && false === strpos( $code, '->refund(' ), 'admin component has no refund path' );
foreach ( array( 'Tự động cập nhật trạng thái đơn WooCommerce', 'Hủy vận đơn SPX không nhất thiết đồng nghĩa', 'Plugin không tự động hoàn tiền', 'Đồng bộ trạng thái WooCommerce' ) as $label ) { a_ok( false !== strpos( $code, $label ), "UI contains: $label" ); }
a_ok( false !== strpos( $code, 'restore_defaults' ), 'settings support restoring defaults' );
a_ok( false === strpos( $code, 'woocommerce_order_details_after_order_table' ) && false === strpos( $code, 'woocommerce_view_order' ), 'audit component is not registered on customer/guest hooks' );
echo "All mapping admin tests passed ($n assertions).\n";
