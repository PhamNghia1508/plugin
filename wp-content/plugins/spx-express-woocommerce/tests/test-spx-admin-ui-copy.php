<?php
/** Phase 6P user-facing Vietnamese and encoding contract. */
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$files = glob( $root . '/includes/admin/*.php' );
$source = '';
foreach ( $files as $file ) { $source .= file_get_contents( $file ); }
$count = 0;
$failed = 0;
function spx_copy_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}
foreach ( array( 'Ã', 'Ä', 'áº', 'á»', 'Â' ) as $bad ) {
	spx_copy_assert( false === strpos( $source, $bad ), 'mojibake marker is absent: ' . $bad );
}
foreach ( array( 'Gate 6F-A', 'Gate 6F-B', 'Phase 6G-A', 'WEBHOOK BLOCKED BY DOCUMENTATION', 'fail-closed' ) as $jargon ) {
	spx_copy_assert( false === stripos( $source, $jargon ), 'technical phase/gate jargon is absent: ' . $jargon );
}
foreach ( array( 'Môi trường', 'Mã đơn nội bộ', 'Liên kết theo dõi', 'Trạng thái tạo vận đơn', 'Tỉnh/Thành phố', 'Quận/Huyện', 'Phường/Xã', 'Mức độ sẵn sàng Production', 'Các mục cần hoàn thiện', 'Thử nghiệm (Sandbox)' ) as $label ) {
	spx_copy_assert( false !== strpos( $source, $label ), 'Vietnamese UI contains ' . $label );
}
foreach ( array( 'Đã áp dụng', 'Không cần thay đổi', 'Đã tắt trong cấu hình', 'Đã xử lý trước đó', 'Trạng thái được bảo vệ', 'Giữ thay đổi thủ công', 'Có lỗi khi xử lý' ) as $label ) {
	spx_copy_assert( false !== strpos( $source, $label ), 'mapping result is localized: ' . $label );
}
if ( $failed ) { echo "SPX admin UI copy RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX admin UI copy PASS ({$count} assertions).\n";

