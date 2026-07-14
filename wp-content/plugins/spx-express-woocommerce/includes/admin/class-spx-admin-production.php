<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Admin_Production {
	const ACTION = 'spx_save_production_settings';
	const NONCE = 'spx_production_settings';

	public static function init(): void { add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) ); }

	public static function merge_submission( array $existing, array $posted ): array {
		$out = $existing;
		foreach ( array( 'app_id', 'user_id', 'shop_id' ) as $field ) { if ( isset( $posted[$field] ) && '' !== trim( (string) $posted[$field] ) ) { $out[$field] = sanitize_text_field( $posted[$field] ); } }
		foreach ( array( 'app_secret', 'user_secret' ) as $field ) {
			if ( 'yes' === ( $posted[ 'delete_' . $field ] ?? '' ) ) { $out[$field] = ''; }
			elseif ( isset( $posted[$field] ) && '' !== trim( (string) $posted[$field] ) ) { $out[$field] = (string) $posted[$field]; }
		}
		unset( $out['base_url'] ); return $out;
	}

	public static function sanitize_requested_state( $state ): string { return 'disabled' === sanitize_key( $state ) ? 'disabled' : 'readiness_pending'; }

	public static function handle_save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Invalid request method.', 'spx-express-woocommerce' ) ); }
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'spx-express-woocommerce' ) ); }
		check_admin_referer( self::NONCE );
		$posted = isset( $_POST['spx_production'] ) && is_array( $_POST['spx_production'] ) ? wp_unslash( $_POST['spx_production'] ) : array();
		$store = new SPX_Credential_Store(); $before = $store->fingerprint( 'production' ); $values = array(); $delete = array();
		foreach ( array( 'app_id', 'app_secret', 'user_id', 'user_secret', 'shop_id' ) as $field ) { $values[$field] = isset( $posted[$field] ) ? sanitize_text_field( $posted[$field] ) : ''; $delete[$field] = ! empty( $posted[ 'delete_' . $field ] ) ? 'yes' : ''; }
		$ok = $store->save( 'production', $values, $delete ); $after = $store->fingerprint( 'production' );
		if ( $before !== $after ) { delete_option( 'spx_production_verified_fingerprint' ); delete_option( 'spx_production_verified_at' ); delete_option( 'spx_production_verification_result' ); }
		$current = SPX_Production_State::normalize( get_option( 'spx_production_state', 'disabled' ) );
		update_option( 'spx_production_state', SPX_Production_State::offline_transition( $current, self::sanitize_requested_state( $posted['state'] ?? 'disabled' ) ), false );
		wp_safe_redirect( add_query_arg( 'spx_notice', $ok ? 'production_saved' : 'production_error', SPX_Admin_Settings_Page::tab_url( 'connection' ) ) ); exit;
	}

	public static function render_section(): void {
		$store = new SPX_Credential_Store(); $credentials = $store->get( 'production' ); $state = SPX_Production_State::normalize( get_option( 'spx_production_state', 'disabled' ) ); $readiness = SPX_Production_Readiness::current();
		echo '<article class="spx-admin-card spx-readiness-card"><div class="spx-card-heading"><h3>' . esc_html__( 'Mức độ sẵn sàng Production', 'spx-express-woocommerce' ) . '</h3>' . SPX_Admin_Settings_Page::status_badge( '', $readiness['ready'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Bị khóa', 'spx-express-woocommerce' ), $readiness['ready'] ? 'success' : 'danger' ) . '</div>';
		echo '<p>' . esc_html__( 'Production Account Verify chưa được thực hiện.', 'spx-express-woocommerce' ) . '</p><p>' . esc_html__( 'Website cần HTTPS trước khi kích hoạt Production.', 'spx-express-woocommerce' ) . '</p><p>' . esc_html__( 'Các vận đơn thật đang bị khóa để bảo vệ cửa hàng.', 'spx-express-woocommerce' ) . '</p>';
		if ( $readiness['failed'] ) { echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Các mục cần hoàn thiện', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><ul class="ul-disc">'; foreach ( $readiness['failed'] as $failed ) { echo '<li>' . esc_html( SPX_Admin_Settings_Page::readiness_label( (string) $failed ) ) . '</li>'; } echo '</ul></div></details>'; }
		echo '</article><article class="spx-admin-card"><h3>' . esc_html__( 'Thông tin Production', 'spx-express-woocommerce' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::NONCE ); echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><div class="spx-form-grid">';
		foreach ( array( 'app_id' => 'App ID', 'app_secret' => 'App secret', 'user_id' => 'User ID', 'user_secret' => 'User secret', 'shop_id' => 'Shop ID' ) as $field => $label ) {
			$server = 'server' === $store->source( 'production', $field ); $secret = in_array( $field, array( 'app_secret', 'user_secret' ), true );
			$id = 'spx_production_' . $field;
			echo '<div class="spx-form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			if ( $server ) { echo '<input id="' . esc_attr( $id ) . '" type="text" value="' . esc_attr__( 'Được quản lý bởi máy chủ', 'spx-express-woocommerce' ) . '" readonly class="regular-text">'; }
			else {
				echo '<input id="' . esc_attr( $id ) . '" type="' . ( $secret ? 'password' : 'text' ) . '" name="spx_production[' . esc_attr( $field ) . ']" value="" autocomplete="new-password" class="regular-text" placeholder="' . esc_attr( '' !== $credentials[$field] ? 'Đã cấu hình — để trống để giữ nguyên' : 'Chưa cấu hình' ) . '">';
				if ( '' !== $credentials[$field] ) { echo '<span class="description"> ' . esc_html__( 'Đã cấu hình', 'spx-express-woocommerce' ) . '</span>'; }
				if ( $secret && '' !== $credentials[$field] ) { $delete_id = $id . '_delete'; echo '<label for="' . esc_attr( $delete_id ) . '" class="spx-delete-secret"><input id="' . esc_attr( $delete_id ) . '" type="checkbox" name="spx_production[delete_' . esc_attr( $field ) . ']" value="yes" data-spx-confirm="' . esc_attr__( 'Xóa thông tin bí mật đã lưu? Hành động chỉ được áp dụng sau khi bấm Lưu.', 'spx-express-woocommerce' ) . '"> ' . esc_html__( 'Xóa thông tin đã lưu', 'spx-express-woocommerce' ) . '</label>'; }
			}
			echo '</div>';
		}
		echo '<div class="spx-form-field"><label for="spx_production_state">' . esc_html__( 'Chế độ Production', 'spx-express-woocommerce' ) . '</label><select id="spx_production_state" name="spx_production[state]"><option value="disabled"' . selected( $state, 'disabled', false ) . '>' . esc_html__( 'Đã tắt', 'spx-express-woocommerce' ) . '</option><option value="readiness_pending"' . selected( $state, 'readiness_pending', false ) . '>' . esc_html__( 'Đang chờ hoàn thiện', 'spx-express-woocommerce' ) . '</option></select></div></div>';
		submit_button( __( 'Lưu cấu hình Production', 'spx-express-woocommerce' ) ); echo ' <button type="button" class="button" disabled aria-disabled="true">' . esc_html__( 'Tạo vận đơn Production — chưa được kích hoạt', 'spx-express-woocommerce' ) . '</button></form></article>';
	}
}
