<?php
defined( 'ABSPATH' ) || exit;

/** Canonical, presentation-only settings surface for SPX Express. */
final class SPX_Admin_Settings_Page {
	const QUERY_KEY = 'spx_tab';

	public static function tabs(): array {
		return array(
			'overview'   => __( 'Tổng quan', 'spx-express-woocommerce' ),
			'connection' => __( 'Kết nối API', 'spx-express-woocommerce' ),
			'sender'     => __( 'Hồ sơ người gửi', 'spx-express-woocommerce' ),
			'addresses'  => __( 'Dữ liệu địa chỉ', 'spx-express-woocommerce' ),
			'rates'      => __( 'Phí vận chuyển', 'spx-express-woocommerce' ),
			'tracking'   => __( 'Tracking', 'spx-express-woocommerce' ),
			'statuses'   => __( 'Trạng thái đơn hàng', 'spx-express-woocommerce' ),
			'tools'      => __( 'Công cụ hệ thống', 'spx-express-woocommerce' ),
		);
	}

	/** @param mixed $requested */
	public static function active_tab( $requested = null ): string {
		if ( null === $requested ) {
			$requested = isset( $_GET[ self::QUERY_KEY ] ) ? wp_unslash( $_GET[ self::QUERY_KEY ] ) : '';
		}
		if ( ! is_scalar( $requested ) ) { return 'overview'; }
		$raw = (string) $requested;
		$key = sanitize_key( $raw );
		if ( $raw !== $key ) { return 'overview'; }
		return isset( self::tabs()[ $key ] ) ? $key : 'overview';
	}

	public static function tab_url( string $tab ): string {
		$tab = self::active_tab( $tab );
		return add_query_arg( array( 'page' => SPX_Admin_Address::PAGE, self::QUERY_KEY => $tab ), admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( SPX_Admin_Address::CAP ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'spx-express-woocommerce' ) );
		}
		$active = self::active_tab();
		echo '<div class="wrap spx-admin-page">';
		echo '<div class="spx-admin-heading"><div><h1>' . esc_html__( 'SPX Express', 'spx-express-woocommerce' ) . '</h1><p>' . esc_html__( 'Quản lý kết nối, địa chỉ, vận chuyển và đồng bộ đơn hàng tại một nơi.', 'spx-express-woocommerce' ) . '</p></div>';
		list( $vlabel, $vtone ) = self::verification_summary();
		echo self::status_badge( __( 'Kết nối SPX', 'spx-express-woocommerce' ), $vlabel, $vtone );
		echo '</div>';
		self::render_notice();
		echo '<nav class="nav-tab-wrapper spx-admin-tabs" aria-label="' . esc_attr__( 'Điều hướng cài đặt SPX Express', 'spx-express-woocommerce' ) . '">';
		foreach ( self::tabs() as $key => $label ) {
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::tab_url( $key ) ) . '"' . ( $active === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav><main class="spx-admin-content">';
		self::render_tab( $active );
		echo '</main></div>';
	}

	private static function render_notice(): void {
		$notice = isset( $_GET['spx_notice'] ) && is_scalar( $_GET['spx_notice'] ) ? sanitize_key( wp_unslash( $_GET['spx_notice'] ) ) : '';
		$notices = array(
			'imported'         => array( 'success', __( 'Đã nhập dữ liệu địa chỉ SPX.', 'spx-express-woocommerce' ) ),
			'import_failed'    => array( 'error', __( 'Không thể nhập dữ liệu. Dữ liệu trước đó vẫn được giữ nguyên.', 'spx-express-woocommerce' ) ),
			'sender_saved'     => array( 'success', __( 'Đã lưu hồ sơ người gửi.', 'spx-express-woocommerce' ) ),
			'sender_error'     => array( 'error', __( 'Vui lòng kiểm tra lại thông tin người gửi.', 'spx-express-woocommerce' ) ),
			'production_saved' => array( 'success', __( 'Đã lưu cấu hình Production.', 'spx-express-woocommerce' ) ),
			'production_error' => array( 'error', __( 'Không thể lưu cấu hình Production.', 'spx-express-woocommerce' ) ),
			'production_verified'        => array( 'success', __( 'Đã xác minh kết nối SPX.', 'spx-express-woocommerce' ) ),
			'production_verify_failed'   => array( 'error', __( 'Không thể xác minh kết nối SPX. Vui lòng kiểm tra thông tin kết nối và thử lại.', 'spx-express-woocommerce' ) ),
			'production_verify_blocked'  => array( 'error', __( 'Chưa thể xác minh: yêu cầu phải chạy từ trang quản trị qua HTTPS với thông tin kết nối đầy đủ.', 'spx-express-woocommerce' ) ),
			'production_verify_cooldown' => array( 'warning', __( 'Vui lòng đợi trong giây lát trước khi xác minh lại.', 'spx-express-woocommerce' ) ),
			'parcel_saved'     => array( 'success', __( 'Đã lưu cấu hình kiện hàng mặc định.', 'spx-express-woocommerce' ) ),
		);
		if ( ! isset( $notices[ $notice ] ) ) { return; }
		echo '<div class="notice notice-' . esc_attr( $notices[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $notice ][1] ) . '</p></div>';
	}

	private static function render_tab( string $active ): void {
		switch ( $active ) {
			case 'connection': self::render_connection(); break;
			case 'sender': SPX_Admin_Address::render_sender_settings(); break;
			case 'addresses': SPX_Admin_Address::render_dataset_settings(); break;
			case 'rates': self::render_rates(); break;
			case 'tracking': SPX_Admin_Tracking::render_section(); break;
			case 'statuses': SPX_Admin_Status_Mapping::render_settings(); break;
			case 'tools': self::render_tools(); break;
			default: self::render_overview(); break;
		}
	}

	private static function render_overview(): void {
		$repo = new SPX_Address_Repository();
		$test = SPX_API_Config::for_test();
		$tracking = SPX_Tracking_Scheduler::settings();
		$mapping = SPX_Woo_Status_Mapping_Policy::settings();
		$readiness = SPX_Production_Readiness::current();
		$dynamic = defined( 'SPX_EXPERIMENTAL_DYNAMIC_RATE' ) && true === SPX_EXPERIMENTAL_DYNAMIC_RATE;
		echo '<section aria-labelledby="spx-overview-title"><h2 id="spx-overview-title">' . esc_html__( 'Tình trạng vận hành', 'spx-express-woocommerce' ) . '</h2>';
		echo '<div class="spx-admin-grid">';
		list( $ov_label, $ov_tone ) = self::verification_summary();
		self::overview_card( __( 'Xác minh kết nối SPX', 'spx-express-woocommerce' ), $ov_label, $ov_tone, __( 'Cấu hình kết nối', 'spx-express-woocommerce' ), 'connection' );
		self::overview_card( __( 'Kết nối API', 'spx-express-woocommerce' ), $test->has_account_credentials() ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Cần cấu hình', 'spx-express-woocommerce' ), $test->has_account_credentials() ? 'success' : 'warning', __( 'Cấu hình kết nối', 'spx-express-woocommerce' ), 'connection' );
		self::overview_card( __( 'Hồ sơ người gửi', 'spx-express-woocommerce' ), SPX_Sender_Profile::is_complete() ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Cần cấu hình', 'spx-express-woocommerce' ), SPX_Sender_Profile::is_complete() ? 'success' : 'warning', __( 'Cập nhật người gửi', 'spx-express-woocommerce' ), 'sender' );
		self::overview_card( __( 'Dữ liệu địa chỉ', 'spx-express-woocommerce' ), $repo->is_available() ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Cần cấu hình', 'spx-express-woocommerce' ), $repo->is_available() ? 'success' : 'warning', __( 'Kiểm tra dữ liệu địa chỉ', 'spx-express-woocommerce' ), 'addresses' );
		self::overview_card( __( 'Phí Checkout', 'spx-express-woocommerce' ), $dynamic ? __( 'Cảnh báo — đang thử nghiệm', 'spx-express-woocommerce' ) : __( 'Phí cố định', 'spx-express-woocommerce' ), $dynamic ? 'warning' : 'neutral', __( 'Cấu hình phí', 'spx-express-woocommerce' ), 'rates' );
		self::overview_card( __( 'Tracking tự động', 'spx-express-woocommerce' ), 'yes' === $tracking['enabled'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Chưa kích hoạt', 'spx-express-woocommerce' ), 'yes' === $tracking['enabled'] ? 'success' : 'neutral', __( 'Bật đồng bộ tracking', 'spx-express-woocommerce' ), 'tracking' );
		self::overview_card( __( 'Mapping trạng thái', 'spx-express-woocommerce' ), 'yes' === $mapping['master_enabled'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Chưa kích hoạt', 'spx-express-woocommerce' ), 'yes' === $mapping['master_enabled'] ? 'success' : 'neutral', __( 'Cấu hình trạng thái', 'spx-express-woocommerce' ), 'statuses' );
		self::overview_card( __( 'Webhook', 'spx-express-woocommerce' ), __( 'Chưa kích hoạt', 'spx-express-woocommerce' ), 'neutral', __( 'Xem tracking', 'spx-express-woocommerce' ), 'tracking' );
		self::overview_card( __( 'Mức độ sẵn sàng Production', 'spx-express-woocommerce' ), $readiness['ready'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Bị khóa', 'spx-express-woocommerce' ), $readiness['ready'] ? 'success' : 'danger', __( 'Xem Production readiness', 'spx-express-woocommerce' ), 'connection' );
		echo '</div></section>';
	}

	private static function overview_card( string $title, string $status, string $tone, string $cta, string $tab ): void {
		echo '<article class="spx-admin-card"><h3>' . esc_html( $title ) . '</h3><p>' . self::status_badge( '', $status, $tone ) . '</p><p><a class="button button-secondary" href="' . esc_url( self::tab_url( $tab ) ) . '">' . esc_html( $cta ) . '</a></p></article>';
	}

	private static function render_connection(): void {
		$store = new SPX_Credential_Store();
		$test = $store->get( 'test' );
		echo '<section aria-labelledby="spx-connection-title"><h2 id="spx-connection-title">' . esc_html__( 'Kết nối API', 'spx-express-woocommerce' ) . '</h2><div class="spx-admin-grid spx-admin-grid--two">';
		echo '<article class="spx-admin-card"><div class="spx-card-heading"><h3>' . esc_html__( 'Sandbox', 'spx-express-woocommerce' ) . '</h3>' . self::status_badge( '', self::credentials_complete( $test, false ) ? __( 'Đã cấu hình', 'spx-express-woocommerce' ) : __( 'Cần cấu hình', 'spx-express-woocommerce' ), self::credentials_complete( $test, false ) ? 'success' : 'warning' ) . '</div>';
		echo '<p><strong>' . esc_html__( 'Địa chỉ API', 'spx-express-woocommerce' ) . ':</strong> <code>https://test-stable.spx.vn/</code></p>';
		self::credential_status_list( $test, false );
		echo '<p class="description">' . esc_html__( 'Thông tin Sandbox được quản lý bằng cấu hình máy chủ. Giá trị bí mật không được hiển thị trên trang.', 'spx-express-woocommerce' ) . '</p></article>';
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'Production', 'spx-express-woocommerce' ) . '</h3><p><strong>' . esc_html__( 'Địa chỉ API', 'spx-express-woocommerce' ) . ':</strong> <code>https://spx.vn/</code></p><p>' . esc_html__( 'Các vận đơn thật đang bị khóa để bảo vệ cửa hàng.', 'spx-express-woocommerce' ) . '</p><p>' . esc_html__( 'Production Account Verify chưa được thực hiện.', 'spx-express-woocommerce' ) . '</p><p>' . esc_html__( 'Website cần HTTPS trước khi kích hoạt Production.', 'spx-express-woocommerce' ) . '</p></article>';
		echo '</div>';
		SPX_Admin_Production::render_section();
		echo '</section>';
	}

	private static function credentials_complete( array $credentials, bool $shop_required ): bool {
		$fields = array( 'app_id', 'app_secret', 'user_id', 'user_secret' );
		if ( $shop_required ) { $fields[] = 'shop_id'; }
		foreach ( $fields as $field ) { if ( empty( $credentials[ $field ] ) ) { return false; } }
		return true;
	}

	private static function credential_status_list( array $credentials, bool $shop_required ): void {
		$labels = array( 'app_id' => 'App ID', 'app_secret' => 'App Secret', 'user_id' => 'User ID', 'user_secret' => 'User Secret' );
		if ( $shop_required ) { $labels['shop_id'] = 'Shop ID'; }
		echo '<ul class="spx-status-list">';
		foreach ( $labels as $field => $label ) {
			echo '<li><span>' . esc_html( $label ) . '</span>' . self::status_badge( '', empty( $credentials[ $field ] ) ? __( 'Chưa cấu hình', 'spx-express-woocommerce' ) : __( 'Đã cấu hình', 'spx-express-woocommerce' ), empty( $credentials[ $field ] ) ? 'neutral' : 'success' ) . '</li>';
		}
		echo '</ul>';
	}

	private static function render_rates(): void {
		$verified = class_exists( 'SPX_Production_Verification_Store' ) && SPX_Production_Verification_Store::is_verified( SPX_Production_Gate::current_fingerprint(), SPX_Environment::host( SPX_Environment::PRODUCTION ) );
		$dynamic = defined( 'SPX_EXPERIMENTAL_DYNAMIC_RATE' ) && true === SPX_EXPERIMENTAL_DYNAMIC_RATE;
		$policy = defined( 'SPX_EXPERIMENTAL_DYNAMIC_RATE_FALLBACK_POLICY' ) ? sanitize_key( (string) SPX_EXPERIMENTAL_DYNAMIC_RATE_FALLBACK_POLICY ) : SPX_Dynamic_Checkout_Rate_Service::POLICY_FIXED_FALLBACK;
		echo '<section aria-labelledby="spx-rates-title"><h2 id="spx-rates-title">' . esc_html__( 'Phí vận chuyển', 'spx-express-woocommerce' ) . '</h2><div class="spx-admin-grid">';
		self::render_instance_fee_cards( $verified );
		echo '<article class="spx-admin-card"><div class="spx-card-heading"><h3>' . esc_html__( 'B. Phí SPX động thử nghiệm', 'spx-express-woocommerce' ) . '</h3>' . self::status_badge( '', $dynamic ? __( 'Đang bật', 'spx-express-woocommerce' ) : __( 'Mặc định tắt', 'spx-express-woocommerce' ), 'warning' ) . '</div><p><strong>' . esc_html__( 'Chỉ Sandbox/Local', 'spx-express-woocommerce' ) . '</strong></p><p>' . esc_html__( 'Hệ số thử nghiệm: 1000. Đơn vị phí và tiền tệ chưa được SPX xác nhận.', 'spx-express-woocommerce' ) . '</p></article>';
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'C. Phí động Production', 'spx-express-woocommerce' ) . '</h3>' . self::status_badge( '', __( 'Bị khóa', 'spx-express-woocommerce' ), 'danger' ) . '<p>' . esc_html__( 'Chưa được kích hoạt và không thể bật từ giao diện này.', 'spx-express-woocommerce' ) . '</p></article>';
		echo '</div><div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Phí SPX động hiện chỉ dùng để thử nghiệm Sandbox. Không sử dụng để thu tiền khách Production cho tới khi SPX xác nhận đơn vị phí.', 'spx-express-woocommerce' ) . '</strong></p></div>';
		echo '<article class="spx-admin-card spx-rate-policy"><h3>' . esc_html__( 'Chính sách khi không lấy được phí động', 'spx-express-woocommerce' ) . '</h3><p>' . esc_html( SPX_Dynamic_Checkout_Rate_Service::POLICY_FIXED_FALLBACK === $policy ? __( 'Phí cố định dự phòng', 'spx-express-woocommerce' ) : __( 'Không cung cấp phương thức SPX khi lỗi', 'spx-express-woocommerce' ) ) . '</p></article>';
		self::render_default_parcel_form();
		echo '</section>';
	}

	/**
	 * Reads the SPX shipping method from every zone (never instance 0 blindly)
	 * and classifies each instance's real, saved fee — distinguishing "Phí do SPX
	 * tính", "Phí dự phòng của shop", "Miễn phí vận chuyển" and "Chưa cấu hình".
	 * Never prints a 30.000đ default for an unconfigured instance.
	 */
	private static function render_instance_fee_cards( bool $verified ): void {
		$instances = self::spx_shipping_instances();
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'Phí giao hàng SPX theo khu vực', 'spx-express-woocommerce' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Phí giao hàng được tính trực tiếp từ SPX sau khi xác minh kết nối. Trước khi xác minh, mỗi khu vực dùng phí dự phòng của shop nếu được cấu hình.', 'spx-express-woocommerce' ) . '</p>';
		if ( ! $instances ) {
			echo '<p>' . esc_html__( 'Chưa có phương thức SPX Express trong khu vực vận chuyển nào.', 'spx-express-woocommerce' ) . '</p></article>';
			return;
		}
		echo '<table class="spx-rate-table spx-rate-table--zones"><thead><tr><th class="spx-th-left">' . esc_html__( 'Khu vực', 'spx-express-woocommerce' ) . '</th><th class="spx-th-left">' . esc_html__( 'Phí áp dụng', 'spx-express-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $instances as $row ) {
			echo '<tr><th class="spx-th-left">' . esc_html( $row['zone'] . ' (#' . $row['instance_id'] . ')' ) . '</th><td>' . wp_kses_post( self::instance_fee_label( $row, $verified ) ) . '</td></tr>';
		}
		echo '</tbody></table></article>';
	}

	private static function instance_fee_label( array $row, bool $verified ): string {
		if ( ! $row['enabled'] ) { return esc_html__( 'Đã tắt', 'spx-express-woocommerce' ); }
		if ( $verified ) { return esc_html__( 'Phí do SPX tính', 'spx-express-woocommerce' ); }
		$base = $row['base_cost'];
		if ( '' === trim( (string) $base ) || ! is_numeric( $base ) ) { return '<span class="spx-status-badge spx-status-badge--warning">' . esc_html__( 'Chưa cấu hình phí', 'spx-express-woocommerce' ) . '</span>'; }
		$base = (float) $base;
		$threshold = is_numeric( $row['threshold'] ) ? (float) $row['threshold'] : 0.0;
		if ( $base <= 0.0 ) {
			return $threshold > 0.0
				? esc_html__( 'Miễn phí khi đạt ngưỡng; ngoài ngưỡng chưa cấu hình phí', 'spx-express-woocommerce' )
				: '<span class="spx-status-badge spx-status-badge--warning">' . esc_html__( 'Chưa cấu hình phí', 'spx-express-woocommerce' ) . '</span>';
		}
		$label = esc_html__( 'Phí dự phòng của shop', 'spx-express-woocommerce' ) . ': ' . wp_kses_post( wc_price( $base ) );
		if ( $threshold > 0.0 ) { $label .= ' — ' . esc_html__( 'miễn phí từ', 'spx-express-woocommerce' ) . ' ' . wp_kses_post( wc_price( $threshold ) ); }
		return $label;
	}

	private static function spx_shipping_instances(): array {
		$rows = array();
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) { return $rows; }
		$zones = (array) WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_name' => __( 'Khu vực còn lại', 'spx-express-woocommerce' ), 'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods() );
		foreach ( $zones as $zone ) {
			$methods = isset( $zone['shipping_methods'] ) ? $zone['shipping_methods'] : array();
			foreach ( (array) $methods as $method ) {
				if ( ! is_object( $method ) || 'spx_express' !== ( $method->id ?? '' ) ) { continue; }
				$rows[] = array(
					'zone'        => (string) ( $zone['zone_name'] ?? '' ),
					'instance_id' => (int) ( $method->instance_id ?? 0 ),
					'enabled'     => 'yes' === ( $method->enabled ?? '' ),
					'base_cost'   => $method->get_option( 'base_cost', '' ),
					'threshold'   => $method->get_option( 'free_shipping_min_amount', '' ),
				);
			}
		}
		return $rows;
	}

	private static function render_default_parcel_form(): void {
		$defaults = SPX_Parcel_Builder::configured_defaults();
		$policy   = SPX_Parcel_Builder::configured_policy();
		$weight_g = (int) round( $defaults['default_weight_kg'] * 1000 );
		$fields   = array(
			'parcel_weight_grams' => array( __( 'Cân nặng mặc định (gram)', 'spx-express-woocommerce' ), $weight_g > 0 ? (string) $weight_g : '', '17000' ),
			'parcel_length_cm'    => array( __( 'Chiều dài mặc định (cm)', 'spx-express-woocommerce' ), $defaults['default_length_cm'] > 0 ? (string) $defaults['default_length_cm'] : '', '60' ),
			'parcel_width_cm'     => array( __( 'Chiều rộng mặc định (cm)', 'spx-express-woocommerce' ), $defaults['default_width_cm'] > 0 ? (string) $defaults['default_width_cm'] : '', '60' ),
			'parcel_height_cm'    => array( __( 'Chiều cao mặc định (cm)', 'spx-express-woocommerce' ), $defaults['default_height_cm'] > 0 ? (string) $defaults['default_height_cm'] : '', '60' ),
		);
		echo '<article class="spx-admin-card"><h3>' . esc_html__( 'Kiện hàng mặc định', 'spx-express-woocommerce' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Giá trị mặc định chỉ được dùng cho sản phẩm chưa khai báo cân nặng hoặc kích thước.', 'spx-express-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="spx_save_parcel">';
		wp_nonce_field( SPX_Admin_Address::NONCE_SAVE );
		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $name => $meta ) {
			echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $meta[0] ) . '</label></th><td><input type="number" min="0" step="0.01" max="' . esc_attr( $meta[2] ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $meta[1] ) . '" class="regular-text"></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Khi sản phẩm thiếu dữ liệu', 'spx-express-woocommerce' ) . '</th><td><select name="parcel_policy">';
		echo '<option value="fail_closed"' . selected( 'fail_closed', $policy, false ) . '>' . esc_html__( 'Không cung cấp vận chuyển SPX', 'spx-express-woocommerce' ) . '</option>';
		echo '<option value="shop_default"' . selected( 'shop_default', $policy, false ) . '>' . esc_html__( 'Dùng thông số kiện hàng mặc định', 'spx-express-woocommerce' ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'Mặc định: không cung cấp vận chuyển SPX khi sản phẩm thiếu cân nặng hoặc kích thước.', 'spx-express-woocommerce' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Lưu kiện hàng mặc định', 'spx-express-woocommerce' ) );
		echo '</form></article>';
	}

	private static function render_tools(): void {
		$repo = new SPX_Address_Repository();
		$meta = $repo->get_dataset_metadata();
		$readiness = SPX_Production_Readiness::current();
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		echo '<section aria-labelledby="spx-tools-title"><h2 id="spx-tools-title">' . esc_html__( 'Công cụ hệ thống', 'spx-express-woocommerce' ) . '</h2><p>' . esc_html__( 'Thông tin kỹ thuật được thu gọn và không chứa thông tin xác thực hoặc dữ liệu cá nhân.', 'spx-express-woocommerce' ) . '</p>';
		echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết hệ thống', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		self::detail( __( 'HPOS', 'spx-express-woocommerce' ), $hpos ? __( 'Đang bật', 'spx-express-woocommerce' ) : __( 'Chưa kích hoạt', 'spx-express-woocommerce' ) );
		self::detail( __( 'Dataset', 'spx-express-woocommerce' ), $repo->is_available() ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Chưa có dữ liệu', 'spx-express-woocommerce' ) );
		self::detail( __( 'Phiên bản dataset', 'spx-express-woocommerce' ), (string) ( $meta['version'] ?? '—' ) );
		self::detail( __( 'Môi trường', 'spx-express-woocommerce' ), __( 'Thử nghiệm (Sandbox)', 'spx-express-woocommerce' ) );
		self::detail( __( 'Mức độ sẵn sàng Production', 'spx-express-woocommerce' ), $readiness['ready'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Bị khóa', 'spx-express-woocommerce' ) );
		echo '</dl>';
		if ( ! empty( $readiness['failed'] ) ) { echo '<p><strong>' . esc_html__( 'Các mục cần hoàn thiện', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( implode( ', ', array_map( array( __CLASS__, 'readiness_label' ), $readiness['failed'] ) ) ) . '</p>'; }
		echo '</div></details></section>';
	}

	public static function readiness_label( string $key ): string {
		$labels = array(
			'credentials' => __( 'Thông tin kết nối', 'spx-express-woocommerce' ),
			'verification' => __( 'Xác minh tài khoản', 'spx-express-woocommerce' ),
			'sender' => __( 'Hồ sơ người gửi', 'spx-express-woocommerce' ),
			'dataset' => __( 'Dữ liệu địa chỉ', 'spx-express-woocommerce' ),
			'https' => __( 'Website HTTPS', 'spx-express-woocommerce' ),
			'hpos' => __( 'HPOS', 'spx-express-woocommerce' ),
			'scheduler' => __( 'Đồng bộ định kỳ', 'spx-express-woocommerce' ),
			'compatibility' => __( 'Tương thích hệ thống', 'spx-express-woocommerce' ),
		);
		return $labels[ $key ] ?? __( 'Cấu hình hệ thống', 'spx-express-woocommerce' );
	}

	/** @return array{0:string,1:string} [label, tone] for the SPX verification state. */
	public static function verification_summary(): array {
		if ( ! class_exists( 'SPX_Production_Verification_Store' ) ) { return array( __( 'Chưa xác minh', 'spx-express-woocommerce' ), 'warning' ); }
		$verified = SPX_Production_Verification_Store::is_verified( SPX_Production_Gate::current_fingerprint(), SPX_Environment::host( SPX_Environment::PRODUCTION ) );
		return $verified ? array( __( 'Đã xác minh', 'spx-express-woocommerce' ), 'success' ) : array( __( 'Chưa xác minh', 'spx-express-woocommerce' ), 'warning' );
	}

	public static function status_badge( string $label, string $value, string $tone = 'neutral' ): string {
		$text = '' === $label ? $value : $label . ': ' . $value;
		return '<span class="spx-admin-status spx-status-badge spx-status-badge--' . esc_attr( sanitize_key( $tone ) ) . '" role="status">' . esc_html( $text ) . '</span>';
	}

	private static function detail( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}
}
