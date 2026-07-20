<?php
defined( 'ABSPATH' ) || exit;

/** Secure settings and admin-only audit UI for controlled SPX status mapping. */
final class SPX_Admin_Status_Mapping {
	const NONCE = 'spx_save_status_mapping';
	const ACTION = 'spx_save_status_mapping';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$settings = SPX_Woo_Status_Mapping_Policy::settings();
		echo '<section aria-labelledby="spx-status-settings-title"><h2 id="spx-status-settings-title">' . esc_html__( 'Tự động cập nhật trạng thái đơn WooCommerce', 'spx-express-woocommerce' ) . '</h2>';
		if ( isset( $_GET['spx_mapping_notice'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['spx_mapping_notice'] ) ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Đã lưu cấu hình đồng bộ trạng thái.', 'spx-express-woocommerce' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"/>';
		echo '<article class="spx-admin-card"><fieldset class="spx-choice-group"><legend>' . esc_html__( 'Trạng thái tự động', 'spx-express-woocommerce' ) . '</legend>';
		self::checkbox( 'master_enabled', __( 'Tự động cập nhật trạng thái WooCommerce theo SPX', 'spx-express-woocommerce' ), $settings );
		echo '</fieldset></article><div class="spx-admin-grid">';
		foreach ( self::grouped_rows() as $group => $rows ) {
			echo '<article class="spx-admin-card"><fieldset class="spx-choice-group"><legend>' . esc_html( $group ) . '</legend>';
			foreach ( $rows as $code => $label ) { self::checkbox( 'map_' . $code, $label, $settings ); }
			echo '</fieldset></article>';
		}
		echo '</div>';
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Hủy vận đơn SPX không nhất thiết đồng nghĩa với hủy đơn bán hàng.', 'spx-express-woocommerce' ) . '</strong></p><p><strong>' . esc_html__( 'Plugin không tự động hoàn tiền.', 'spx-express-woocommerce' ) . '</strong></p></div>';
		submit_button( __( 'Lưu cấu hình', 'spx-express-woocommerce' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Khôi phục mặc định', 'spx-express-woocommerce' ), 'secondary', 'restore_defaults', false, array( 'value' => '1' ) );
		echo '</form></section>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'spx-express-woocommerce' ) ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Invalid request method.', 'spx-express-woocommerce' ) ); }
		check_admin_referer( self::NONCE );
		$post = wp_unslash( $_POST );
		$settings = isset( $post['restore_defaults'] )
			? SPX_Woo_Status_Mapping_Policy::defaults()
			: SPX_Woo_Status_Mapping_Policy::sanitize( isset( $post['settings'] ) && is_array( $post['settings'] ) ? $post['settings'] : array() );
		update_option( SPX_Woo_Status_Mapping_Policy::OPTION, $settings, false );
		update_option( SPX_Woo_Status_Mapping_Policy::VERSION_OPTION, SPX_Woo_Status_Mapping_Policy::SETTINGS_VERSION, false );
		$url = add_query_arg( 'spx_mapping_notice', 'saved', SPX_Admin_Settings_Page::tab_url( 'statuses' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_order_panel( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$code = (string) $order->get_meta( '_spx_shipment_status_code', true );
		$last = (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_CODE, true );
		if ( '' === $code && '' === $last ) { return; }
		$mapping = SPX_Woo_Status_Mapping_Policy::mapping( $code );
		$status = '' !== $code ? SPX_Tracking_Status_Mapper::map( $code ) : array( 'customer_label' => '—' );
		echo '<div class="spx-woo-status-audit"><h3>' . esc_html__( 'Đồng bộ trạng thái WooCommerce', 'spx-express-woocommerce' ) . '</h3>';
		self::row( __( 'Trạng thái SPX hiện tại', 'spx-express-woocommerce' ), $status['customer_label'] . ( '' !== $code ? ' (' . $code . ')' : '' ) );
		self::row( __( 'Trạng thái WooCommerce hiện tại', 'spx-express-woocommerce' ), wc_get_order_status_name( $order->get_status() ) );
		self::row( __( 'Mapping tương ứng', 'spx-express-woocommerce' ), '' === $mapping['target'] ? __( 'Không thay đổi', 'spx-express-woocommerce' ) : wc_get_order_status_name( $mapping['target'] ) );
		self::row( __( 'Mapping', 'spx-express-woocommerce' ), $mapping['enabled'] ? __( 'Bật', 'spx-express-woocommerce' ) : __( 'Tắt', 'spx-express-woocommerce' ) );
		self::row( __( 'Lần mapping gần nhất', 'spx-express-woocommerce' ), (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_AT, true ) );
		$result = sanitize_key( (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_RESULT, true ) );
		self::row( __( 'Kết quả', 'spx-express-woocommerce' ), self::result_label( $result ) );
		$source = sanitize_key( (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_SOURCE, true ) );
		self::row( __( 'Nguồn cập nhật', 'spx-express-woocommerce' ), self::source_label( $source ) );
		echo '</div>';
	}

	private static function checkbox( string $key, string $label, array $settings ) {
		$id = 'spx_mapping_' . sanitize_key( $key );
		echo '<p><label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="yes" ' . checked( 'yes', $settings[ $key ], false ) . '/> ' . esc_html( $label ) . '</label></p>';
	}

	private static function grouped_rows(): array {
		$rows = self::rows();
		return array(
			__( 'Tự động hoàn tất', 'spx-express-woocommerce' ) => array( '4001' => $rows['4001'] ),
			__( 'Đưa vào kiểm tra', 'spx-express-woocommerce' ) => array( '3001' => $rows['3001'], '5002' => $rows['5002'], '5003' => $rows['5003'], '6001' => $rows['6001'] ),
			__( 'Tùy chọn nâng cao', 'spx-express-woocommerce' ) => array( '5001' => $rows['5001'], '6002' => $rows['6002'], '6003' => $rows['6003'], '7001' => $rows['7001'] ),
		);
	}

	private static function rows(): array {
		return array(
			'4001' => __( 'SPX giao thành công → Hoàn thành đơn hàng', 'spx-express-woocommerce' ),
			'3001' => __( 'SPX tạm giữ → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'5002' => __( 'SPX báo hư hỏng → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'5003' => __( 'SPX báo thất lạc → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'6001' => __( 'SPX đang hoàn hàng → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'5001' => __( 'SPX lấy hàng thất bại → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'6002' => __( 'SPX hoàn hàng thất bại → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'6003' => __( 'SPX đã hoàn về shop → Tạm giữ đơn hàng', 'spx-express-woocommerce' ),
			'7001' => __( 'SPX hủy vận đơn → Hủy đơn WooCommerce', 'spx-express-woocommerce' ),
		);
	}

	public static function result_label( string $result ): string {
		$labels = array(
			'applied'                   => __( 'Đã áp dụng', 'spx-express-woocommerce' ),
			'no_action'                 => __( 'Không cần thay đổi', 'spx-express-woocommerce' ),
			'disabled'                  => __( 'Đã tắt trong cấu hình', 'spx-express-woocommerce' ),
			'already_applied'           => __( 'Đã xử lý trước đó', 'spx-express-woocommerce' ),
			'protected_status'          => __( 'Trạng thái được bảo vệ', 'spx-express-woocommerce' ),
			'manual_override_preserved' => __( 'Giữ thay đổi thủ công', 'spx-express-woocommerce' ),
			'error'                     => __( 'Có lỗi khi xử lý', 'spx-express-woocommerce' ),
		);
		return $labels[ $result ] ?? '—';
	}

	public static function source_label( string $source ): string {
		$labels = array( 'scheduler' => __( 'Đồng bộ định kỳ', 'spx-express-woocommerce' ), 'manual' => __( 'Đồng bộ thủ công', 'spx-express-woocommerce' ), 'webhook' => __( 'Webhook', 'spx-express-woocommerce' ), 'unknown' => __( 'Không xác định', 'spx-express-woocommerce' ) );
		return $labels[ $source ] ?? $labels['unknown'];
	}

	private static function row( string $label, string $value ) {
		if ( '' !== $value ) { echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>'; }
	}
}
