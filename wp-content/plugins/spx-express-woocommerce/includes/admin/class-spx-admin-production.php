<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Admin_Production {
	const ACTION = 'spx_save_production_settings';
	const NONCE = 'spx_production_settings';
	const VERIFY_ACTION = 'spx_verify_production';
	const VERIFY_NONCE  = 'spx_verify_production';
	const COOLDOWN_KEY  = 'spx_production_verify_cooldown';
	const COOLDOWN_TTL  = 30;

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::VERIFY_ACTION, array( __CLASS__, 'handle_verify' ) );
	}

	/**
	 * Bootstrap Account Verify handler. Runs ONLY on an explicit admin POST — it
	 * is never auto-invoked. Enforces the gate's bootstrap policy (admin HTTPS),
	 * a short cooldown against rapid re-submits, and persists the verification
	 * marker on success. On any failure the marker is invalidated (fail closed).
	 */
	public static function handle_verify(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Invalid request method.', 'spx-express-woocommerce' ), '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'spx-express-woocommerce' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( self::VERIFY_NONCE );
		$url = SPX_Admin_Settings_Page::tab_url( 'connection' );
		if ( get_transient( self::COOLDOWN_KEY ) ) { wp_safe_redirect( add_query_arg( 'spx_notice', 'production_verify_cooldown', $url ) ); exit; }
		set_transient( self::COOLDOWN_KEY, 1, self::COOLDOWN_TTL );
		$ctx = SPX_Production_Gate::runtime_context();
		if ( ! SPX_Production_Gate::allows( 'account_verify', $ctx ) ) {
			SPX_Production_Verification_Store::invalidate( 'bootstrap_' . SPX_Production_Gate::evaluate( 'account_verify', $ctx )['reason'] );
			wp_safe_redirect( add_query_arg( 'spx_notice', 'production_verify_blocked', $url ) ); exit;
		}
		$config  = SPX_API_Config::for_production();
		$client  = new SPX_HTTP_Client( $config, new SPX_Request_Signer(), null );
		$result  = ( new SPX_Account_Service( $config, $client ) )->verify_credentials();
		if ( ! empty( $result['success'] ) && ! empty( $result['verified'] ) ) {
			$store = new SPX_Credential_Store();
			SPX_Production_Verification_Store::mark_verified( $store->fingerprint( SPX_Environment::PRODUCTION ), SPX_Environment::host( SPX_Environment::PRODUCTION ) );
			wp_safe_redirect( add_query_arg( 'spx_notice', 'production_verified', $url ) ); exit;
		}
		SPX_Production_Verification_Store::invalidate( 'verify_failed' );
		wp_safe_redirect( add_query_arg( 'spx_notice', 'production_verify_failed', $url ) ); exit;
	}

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
		if ( $before !== $after ) { delete_option( 'spx_production_verified_fingerprint' ); delete_option( 'spx_production_verified_at' ); delete_option( 'spx_production_verification_result' ); SPX_Production_Verification_Store::invalidate( 'credentials_changed' ); }
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
		submit_button( __( 'Lưu cấu hình Production', 'spx-express-woocommerce' ) ); echo '</form>';
		self::render_verification_status();
		echo '</article>';
	}

	/** Masked verification status + the (non-auto-invoked) verify button. */
	private static function render_verification_status(): void {
		$marker      = SPX_Production_Verification_Store::get();
		$fingerprint = SPX_Production_Gate::current_fingerprint();
		$host        = SPX_Environment::host( SPX_Environment::PRODUCTION );
		$verified    = SPX_Production_Verification_Store::is_verified( $fingerprint, $host );
		$state       = SPX_Production_Verification_Store::state( $fingerprint, $host );
		$label       = $verified ? __( 'Đã xác minh', 'spx-express-woocommerce' ) : ( 'invalidated' === $state ? __( 'Cần xác minh lại', 'spx-express-woocommerce' ) : __( 'Chưa xác minh', 'spx-express-woocommerce' ) );
		echo '<div class="spx-verification-status"><p><strong>' . esc_html__( 'Trạng thái xác minh SPX', 'spx-express-woocommerce' ) . ':</strong> ' . SPX_Admin_Settings_Page::status_badge( '', $label, $verified ? 'success' : 'warning' ) . '</p>';
		if ( $verified && '' !== $marker['verified_at'] ) { echo '<p><strong>' . esc_html__( 'Thời điểm xác minh', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( $marker['verified_at'] ) . '</p>'; }
		if ( ! $verified ) {
			echo '<p class="description">' . ( 'invalidated' === $state
				? esc_html__( 'Thông tin kết nối đã thay đổi. Vui lòng xác minh lại.', 'spx-express-woocommerce' )
				: esc_html__( 'Kết nối SPX chưa được xác minh. Vui lòng xác minh kết nối trước khi sử dụng phí và vận đơn SPX.', 'spx-express-woocommerce' ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::VERIFY_NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::VERIFY_ACTION ) . '">';
		submit_button( __( 'Xác minh kết nối SPX', 'spx-express-woocommerce' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
