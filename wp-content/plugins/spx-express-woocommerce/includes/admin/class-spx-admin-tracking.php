<?php
defined( 'ABSPATH' ) || exit;
final class SPX_Admin_Tracking {
	const NONCE = 'spx_tracking_admin';
	public static function init(): void { add_action( 'admin_post_spx_save_tracking', array( __CLASS__, 'save' ) ); add_action( 'admin_post_spx_sync_tracking_now', array( __CLASS__, 'sync_now' ) ); }
	public static function render_section(): void {
		$s = SPX_Tracking_Scheduler::settings();
		$health = wp_parse_args( get_option( 'spx_tracking_health', array() ), array( 'queried' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'retry' => 0, 'last_error' => '', 'last_success' => '' ) );
		$eligible = ( new SPX_Tracking_Order_Query() )->page( 1, (int) $s['batch_size'] );
		$queued = function_exists( 'as_has_scheduled_action' ) ? as_has_scheduled_action( SPX_Tracking_Scheduler::HOOK, array(), SPX_Tracking_Scheduler::TEST_GROUP ) : (bool) wp_next_scheduled( 'spx_tracking_cron' );
		echo '<section aria-labelledby="spx-tracking-title"><h2 id="spx-tracking-title">' . esc_html__( 'Đồng bộ tracking', 'spx-express-woocommerce' ) . '</h2>';
		$notice = isset( $_GET['spx_tracking_notice'] ) ? sanitize_key( wp_unslash( $_GET['spx_tracking_notice'] ) ) : '';
		if ( 'enqueued' === $notice ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Yêu cầu đồng bộ SPX đã được xếp hàng.', 'spx-express-woocommerce' ) . '</p></div>'; }
		echo '<div class="spx-admin-grid spx-admin-grid--two"><article class="spx-admin-card"><div class="spx-card-heading"><h3>' . esc_html__( 'Đồng bộ tự động', 'spx-express-woocommerce' ) . '</h3>' . SPX_Admin_Settings_Page::status_badge( '', 'yes' === $s['enabled'] ? __( 'Đang bật', 'spx-express-woocommerce' ) : __( 'Chưa kích hoạt', 'spx-express-woocommerce' ), 'yes' === $s['enabled'] ? 'success' : 'neutral' ) . '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="spx_save_tracking"><fieldset class="spx-choice-group"><legend>' . esc_html__( 'Trạng thái đồng bộ', 'spx-express-woocommerce' ) . '</legend><label for="spx_tracking_enabled"><input id="spx_tracking_enabled" type="checkbox" name="enabled" value="yes" ' . checked( 'yes', $s['enabled'], false ) . '> ' . esc_html__( 'Bật đồng bộ tự động', 'spx-express-woocommerce' ) . '</label></fieldset><div class="spx-form-grid">';
		echo '<div class="spx-form-field"><label for="spx_tracking_interval">' . esc_html__( 'Chu kỳ (phút)', 'spx-express-woocommerce' ) . '</label><select id="spx_tracking_interval" name="interval">'; foreach ( array( 5, 15, 30, 60 ) as $v ) { echo '<option value="' . esc_attr( (string) $v ) . '" ' . selected( (int) $s['interval'], $v, false ) . '>' . esc_html( (string) $v ) . '</option>'; } echo '</select></div>';
		echo '<div class="spx-form-field"><label for="spx_tracking_batch_size">' . esc_html__( 'Số đơn mỗi lượt', 'spx-express-woocommerce' ) . '</label><input id="spx_tracking_batch_size" type="number" min="1" max="100" name="batch_size" value="' . esc_attr( (string) $s['batch_size'] ) . '"></div></div>';
		submit_button( __( 'Lưu cấu hình tracking', 'spx-express-woocommerce' ), 'primary', 'submit', false ); echo '</form></article>';
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'Đồng bộ thủ công', 'spx-express-woocommerce' ) . '</h3><p>' . esc_html( sprintf( __( 'Có %d đơn đủ điều kiện trong lượt tiếp theo.', 'spx-express-woocommerce' ), count( $eligible['orders'] ) ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::NONCE ); echo '<input type="hidden" name="action" value="spx_sync_tracking_now">'; submit_button( __( 'Đồng bộ ngay', 'spx-express-woocommerce' ), 'secondary', 'submit', false ); echo '</form></article></div>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Webhook chưa được kích hoạt vì SPX chưa cung cấp đầy đủ tài liệu xác thực. Plugin hiện sử dụng đồng bộ định kỳ.', 'spx-express-woocommerce' ) . '</p></div>';
		$counts = self::live_action_counts();
		$active = 'yes' === $s['enabled'] && $queued;
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'Tình trạng đồng bộ', 'spx-express-woocommerce' ) . '</h3><dl class="spx-definition-list">';
		foreach ( array(
			__( 'Đồng bộ định kỳ', 'spx-express-woocommerce' ) => $active ? __( 'Đang hoạt động', 'spx-express-woocommerce' ) : __( 'Chưa hoạt động', 'spx-express-woocommerce' ),
			__( 'Chu kỳ', 'spx-express-woocommerce' ) => sprintf( __( '%d phút', 'spx-express-woocommerce' ), (int) $s['interval'] ),
			__( 'Trạng thái kết nối SPX', 'spx-express-woocommerce' ) => ( class_exists( 'SPX_Production_Verification_Store' ) && SPX_Production_Verification_Store::is_verified( SPX_Production_Gate::current_fingerprint(), SPX_Environment::host( 'production' ) ) ) ? __( 'Đã xác minh', 'spx-express-woocommerce' ) : __( 'Chưa xác minh', 'spx-express-woocommerce' ),
			__( 'Số lượt đồng bộ đang chờ', 'spx-express-woocommerce' ) => (string) $counts['pending'],
			__( 'Số lần thử lại đang chờ', 'spx-express-woocommerce' ) => (string) $counts['retry'],
			__( 'Số tác vụ lỗi', 'spx-express-woocommerce' ) => (string) $counts['failed'],
			__( 'Lần chạy gần nhất', 'spx-express-woocommerce' ) => (string) get_option( 'spx_tracking_last_run', '—' ),
		) as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
		echo '</dl></article>';
		echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết đồng bộ', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		foreach ( array( __( 'Lần chạy gần nhất', 'spx-express-woocommerce' ) => get_option( 'spx_tracking_last_run', '—' ), __( 'Hàng đợi', 'spx-express-woocommerce' ) => $queued ? __( 'Đã lên lịch', 'spx-express-woocommerce' ) : __( 'Chưa lên lịch', 'spx-express-woocommerce' ), __( 'Đã xử lý', 'spx-express-woocommerce' ) => $health['queried'], __( 'Đã cập nhật', 'spx-express-woocommerce' ) => $health['updated'], __( 'Bỏ qua', 'spx-express-woocommerce' ) => $health['skipped'], __( 'Thất bại', 'spx-express-woocommerce' ) => $health['failed'], __( 'Chờ thử lại', 'spx-express-woocommerce' ) => $health['retry'], __( 'Thành công gần nhất', 'spx-express-woocommerce' ) => $health['last_success'] ?: '—', __( 'Lỗi gần nhất', 'spx-express-woocommerce' ) => $health['last_error'] ?: '—' ) as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>'; }
		echo '</dl></div></details></section>';
	}
	/** Live Action Scheduler counts for the Test group — counts only, never raw args or PII. */
	private static function live_action_counts(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) { return array( 'pending' => 0, 'retry' => 0, 'failed' => 0 ); }
		$group = SPX_Tracking_Scheduler::TEST_GROUP;
		$count = static function ( string $hook, string $status ) use ( $group ): int {
			return count( (array) as_get_scheduled_actions( array( 'hook' => $hook, 'group' => $group, 'status' => $status, 'per_page' => 200 ), 'ids' ) );
		};
		return array(
			'pending' => $count( SPX_Tracking_Scheduler::HOOK, 'pending' ),
			'retry'   => $count( SPX_Tracking_Scheduler::RETRY_HOOK, 'pending' ),
			'failed'  => $count( SPX_Tracking_Scheduler::HOOK, 'failed' ) + $count( SPX_Tracking_Scheduler::RETRY_HOOK, 'failed' ),
		);
	}

	private static function guard(): void { if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'spx-express-woocommerce' ) ); } check_admin_referer( self::NONCE ); }
	public static function save(): void { self::guard(); update_option( 'spx_tracking_settings', array( 'enabled' => isset( $_POST['enabled'] ) ? 'yes' : 'no', 'interval' => max( 5, min( 60, absint( $_POST['interval'] ?? 15 ) ) ), 'batch_size' => max( 1, min( 100, absint( $_POST['batch_size'] ?? 100 ) ) ) ), false ); SPX_Tracking_Scheduler::unschedule(); SPX_Tracking_Scheduler::ensure_schedule(); self::back(); }
	public static function sync_now(): void { self::guard(); SPX_Tracking_Scheduler::enqueue_now(); self::back( 'enqueued' ); }
	private static function back( string $tracking_notice = '' ): void {
		$url = SPX_Admin_Settings_Page::tab_url( 'tracking' );
		if ( $tracking_notice ) { $url = add_query_arg( 'spx_tracking_notice', sanitize_key( $tracking_notice ), $url ); }
		wp_safe_redirect( $url ); exit;
	}
}
