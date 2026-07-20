<?php
/**
 * Dọn dữ liệu khi GỠ (xoá) plugin.
 *
 * Chỉ chạy khi người dùng bấm "Xoá" plugin trong Plugins (không chạy khi
 * "Tắt/Deactivate"). Dọn các option cấu hình + khoá tạm của plugin, GIỮ LẠI
 * dữ liệu vận đơn gắn trên đơn hàng (order meta) để không phá lịch sử đơn -
 * nếu khách cài lại sau này thì tra cứu/hành trình cũ vẫn còn.
 *
 * @package SuperShip_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1) Xoá toàn bộ option cấu hình của plugin (mọi thứ có tiền tố supership_).
$option_names = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE 'supership\_%'
	    OR option_name = 'spx_tracking_sync_lock'"
);

foreach ( (array) $option_names as $option_name ) {
	delete_option( $option_name );
}

// 2) Cài đặt instance của phương thức vận chuyển SuperShip (WooCommerce lưu
//    dạng woocommerce_supership_{instance_id}_settings).
$shipping_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE 'woocommerce\_supership\_%\_settings'"
);

foreach ( (array) $shipping_options as $option_name ) {
	delete_option( $option_name );
}

// 3) Transient của plugin (cache kết nối, rate-limit tra cứu, cache địa chỉ...).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_supership\_%'
	    OR option_name LIKE '\_transient\_timeout\_supership\_%'"
);

// 4) Sự kiện cron theo dõi vận đơn (phòng khi deactivate hook chưa kịp gỡ).
wp_clear_scheduled_hook( 'supership_tracking_sync' );

// Lưu ý (cố ý GIỮ LẠI):
// - Order meta: _supership_tracking_number, _supership_status,
//   _supership_status_name, _supership_journeys... -> giữ để bảo toàn lịch sử đơn.
// - Trang "Tra cứu đơn hàng": giữ để không xoá nội dung do chủ shop có thể đã
//   chỉnh sửa; chủ shop tự xoá trang trong Trang nếu muốn.
