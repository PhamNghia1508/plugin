<?php
defined( 'ABSPATH' ) || exit;

/** Canonical, presentation-only SPX order administration surface. */
final class SPX_Admin_Order_Metabox {
	const ID = 'spx-express-order';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 20, 2 );
	}

	public static function screen_ids(): array {
		$screens = array( 'shop_order' );
		$screens[] = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		return array_values( array_unique( array_filter( $screens ) ) );
	}

	public static function register( $post_type = '', $post = null ): void {
		foreach ( self::screen_ids() as $screen ) {
			add_meta_box( self::ID, __( 'SPX Express', 'spx-express-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'normal', 'high' );
		}
	}

	/** @param mixed $post_or_order */
	public static function render( $post_or_order ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) { return; }
		$payment = SPX_Payment_Resolver::resolve( $order );
		$readiness = SPX_Admin_Shipment::readiness_for_order( $order );
		$context = self::action_context( $order, $readiness );
		$actions = self::action_visibility( $context );
		echo '<div class="spx-order-metabox">';
		self::render_shipment_summary( $order, $readiness );
		self::render_address( $order );
		self::render_parcel( $order );
		self::render_payment( $order, $payment );
		self::render_rate( $order );
		self::render_status( $order );
		self::render_actions( $order, $actions );
		echo '</div>';
	}

	/** @param mixed $value */
	private static function resolve_order( $value ) {
		if ( $value instanceof WC_Order ) { return $value; }
		if ( is_object( $value ) && isset( $value->ID ) ) { return wc_get_order( absint( $value->ID ) ); }
		if ( is_numeric( $value ) ) { return wc_get_order( absint( $value ) ); }
		return null;
	}

	private static function render_shipment_summary( WC_Order $order, array $readiness ): void {
		$tracking = (string) $order->get_meta( '_spx_tracking_number', true );
		$code = (string) $order->get_meta( '_spx_shipment_status_code', true );
		$status = '' !== $code ? SPX_Tracking_Status_Mapper::map( $code )['customer_label'] : (string) $order->get_meta( '_spx_shipment_status_label', true );
		$environment = SPX_Order_Environment::get( $order );
		echo '<section class="spx-order-section spx-order-summary" aria-labelledby="spx-order-summary-title"><div class="spx-section-heading"><h3 id="spx-order-summary-title">' . esc_html__( 'Tổng quan vận đơn', 'spx-express-woocommerce' ) . '</h3>';
		echo SPX_Admin_Settings_Page::status_badge( '', $readiness['ready'] ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Chưa sẵn sàng', 'spx-express-woocommerce' ), $readiness['ready'] ? 'success' : 'warning' ) . '</div>';
		if ( '' === $tracking ) {
			echo '<p class="spx-empty-state"><strong>' . esc_html__( 'Chưa tạo vận đơn.', 'spx-express-woocommerce' ) . '</strong></p>';
			if ( ! $readiness['ready'] ) {
				echo '<ul class="spx-readiness-list">';
				foreach ( $readiness['errors'] as $error ) { echo '<li>' . esc_html( (string) ( $error['message'] ?? __( 'Cần bổ sung thông tin vận đơn.', 'spx-express-woocommerce' ) ) ) . '</li>'; }
				echo '</ul>';
			}
			echo '</section>';
			return;
		}
		echo '<div class="spx-order-summary__grid">';
		self::summary_item( __( 'Mã vận đơn', 'spx-express-woocommerce' ), $tracking );
		self::summary_item( __( 'Trạng thái', 'spx-express-woocommerce' ), $status ?: __( 'Chưa có trạng thái', 'spx-express-woocommerce' ) );
		self::summary_item( __( 'Môi trường', 'spx-express-woocommerce' ), 'production' === $environment ? __( 'Production', 'spx-express-woocommerce' ) : __( 'Thử nghiệm (Sandbox)', 'spx-express-woocommerce' ) );
		self::summary_item( __( 'Ngày tạo', 'spx-express-woocommerce' ), (string) $order->get_meta( '_spx_shipment_created_at', true ) );
		self::summary_item( __( 'Đồng bộ gần nhất', 'spx-express-woocommerce' ), (string) $order->get_meta( '_spx_last_sync_at', true ) );
		$link = (string) $order->get_meta( '_spx_tracking_link', true );
		if ( self::safe_https( $link ) ) { echo '<div class="spx-summary-item"><span>' . esc_html__( 'Liên kết theo dõi', 'spx-express-woocommerce' ) . '</span><strong><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Mở trang theo dõi', 'spx-express-woocommerce' ) . '</a></strong></div>'; }
		echo '</div></section>';
	}

	private static function summary_item( string $label, string $value ): void {
		echo '<div class="spx-summary-item"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( '' !== $value ? $value : '—' ) . '</strong></div>';
	}

	private static function render_address( WC_Order $order ): void {
		$repo = new SPX_Address_Repository();
		$effective = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
		$override = SPX_Order_Address::get( $order );
		$summary = array_filter( array( $effective['province_name'] ?? '', $effective['district_name'] ?? '', $effective['ward_name'] ?? '' ) );
		echo '<section class="spx-order-section" aria-labelledby="spx-order-address-title"><div class="spx-section-heading"><h3 id="spx-order-address-title">' . esc_html__( 'Địa chỉ giao hàng SPX', 'spx-express-woocommerce' ) . '</h3>' . SPX_Admin_Settings_Page::status_badge( '', SPX_Checkout_Address_Snapshot::source_label( (string) ( $effective['source'] ?? '' ) ), 'neutral' ) . '</div>';
		echo '<p class="spx-address-summary">' . esc_html( $summary ? implode( ' / ', $summary ) : __( 'Chưa xác định đủ khu vực SPX.', 'spx-express-woocommerce' ) ) . '</p>';
		if ( ! $repo->is_available() ) { echo '<p>' . esc_html__( 'Cần nhập dữ liệu địa chỉ trước khi chỉnh sửa.', 'spx-express-woocommerce' ) . '</p></section>'; return; }
		$provinces = $repo->get_provinces();
		$districts = $override['province_code'] ? $repo->get_districts( $override['province_code'] ) : array();
		$wards = $override['district_code'] ? $repo->get_wards( $override['district_code'] ) : array();
		echo '<details class="spx-address-editor"><summary>' . esc_html__( 'Chỉnh sửa địa chỉ SPX', 'spx-express-woocommerce' ) . '</summary><div class="spx-form-grid" data-spx-location-picker>';
		wp_nonce_field( SPX_Admin_Address::NONCE_SAVE, 'spx_order_nonce' );
		self::select_field( 'spx_o_province_code', __( 'Tỉnh/Thành phố', 'spx-express-woocommerce' ), $provinces, (string) $override['province_code'] );
		self::select_field( 'spx_o_district_code', __( 'Quận/Huyện', 'spx-express-woocommerce' ), $districts, (string) $override['district_code'] );
		self::select_field( 'spx_o_ward_code', __( 'Phường/Xã', 'spx-express-woocommerce' ), $wards, (string) $override['ward_code'] );
		echo '</div></details><details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết kỹ thuật', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		self::detail( __( 'Nguồn địa chỉ', 'spx-express-woocommerce' ), SPX_Checkout_Address_Snapshot::source_label( (string) ( $effective['source'] ?? '' ) ) );
		self::detail( __( 'Mã Tỉnh/Thành phố', 'spx-express-woocommerce' ), (string) ( $effective['province_code'] ?? '' ) );
		self::detail( __( 'Mã Quận/Huyện', 'spx-express-woocommerce' ), (string) ( $effective['district_code'] ?? '' ) );
		self::detail( __( 'Mã Phường/Xã', 'spx-express-woocommerce' ), (string) ( $effective['ward_code'] ?? '' ) );
		$snapshot = SPX_Checkout_Address_Snapshot::read_from_order( $order );
		self::detail( __( 'Phiên bản dataset', 'spx-express-woocommerce' ), (string) ( $snapshot['dataset_version'] ?? '' ) );
		self::detail( __( 'Hỗ trợ giao hàng', 'spx-express-woocommerce' ), ! empty( $snapshot['delivery_supported'] ) ? __( 'Có', 'spx-express-woocommerce' ) : __( 'Chưa xác định', 'spx-express-woocommerce' ) );
		self::detail( __( 'Hỗ trợ COD', 'spx-express-woocommerce' ), ! empty( $snapshot['cod_supported'] ) ? __( 'Có', 'spx-express-woocommerce' ) : __( 'Chưa xác định', 'spx-express-woocommerce' ) );
		echo '</dl></div></details></section>';
	}

	private static function select_field( string $id, string $label, array $items, string $selected ): void {
		echo '<div class="spx-form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '"><option value="">—</option>';
		foreach ( $items as $item ) { echo '<option value="' . esc_attr( $item['code'] ) . '"' . selected( $selected, $item['code'], false ) . '>' . esc_html( $item['name'] ) . '</option>'; }
		echo '</select></div>';
	}

	private static function render_parcel( WC_Order $order ): void {
		$persisted = (string) $order->get_meta( '_spx_parcel_weight_kg', true );
		if ( '' !== $persisted ) {
			$weight_kg = (float) $persisted;
			$length    = (float) $order->get_meta( '_spx_parcel_length_cm', true );
			$width     = (float) $order->get_meta( '_spx_parcel_width_cm', true );
			$height    = (float) $order->get_meta( '_spx_parcel_height_cm', true );
			$source    = (string) $order->get_meta( '_spx_parcel_source', true );
			$version   = (string) $order->get_meta( '_spx_parcel_policy_version', true );
			$valid     = true;
			$errors    = array();
		} else {
			$payload   = SPX_Order_Mapper::build( $order, SPX_Settings::get_instance_settings() );
			$weight_kg = (float) ( $payload['weight_grams'] ?? 0 ) / 1000;
			$length    = (float) ( $payload['length_cm'] ?? 0 );
			$width     = (float) ( $payload['width_cm'] ?? 0 );
			$height    = (float) ( $payload['height_cm'] ?? 0 );
			$source    = (string) ( $payload['parcel_source'] ?? '' );
			$version   = isset( $payload['parcel']['_spx_parcel_policy_version'] ) ? (string) $payload['parcel']['_spx_parcel_policy_version'] : '';
			$valid     = ! empty( $payload['parcel_valid'] );
			$errors    = isset( $payload['parcel_errors'] ) && is_array( $payload['parcel_errors'] ) ? $payload['parcel_errors'] : array();
		}
		$dims = ( $length > 0 && $width > 0 && $height > 0 ) ? sprintf( '%s × %s × %s cm', $length, $width, $height ) : __( 'Chưa có kích thước', 'spx-express-woocommerce' );
		echo '<section class="spx-order-section" aria-labelledby="spx-order-parcel-title"><div class="spx-section-heading"><h3 id="spx-order-parcel-title">' . esc_html__( 'Thông tin kiện hàng', 'spx-express-woocommerce' ) . '</h3>'
			. SPX_Admin_Settings_Page::status_badge( '', $valid ? __( 'Hợp lệ', 'spx-express-woocommerce' ) : __( 'Chưa hợp lệ', 'spx-express-woocommerce' ), $valid ? 'success' : 'warning' ) . '</div>';
		echo '<div class="spx-order-summary__grid">';
		self::summary_item( __( 'Cân nặng', 'spx-express-woocommerce' ), $weight_kg > 0 ? sprintf( '%s kg', $weight_kg ) : __( 'Chưa có cân nặng', 'spx-express-woocommerce' ) );
		self::summary_item( __( 'Dài × Rộng × Cao', 'spx-express-woocommerce' ), $dims );
		self::summary_item( __( 'Nguồn dữ liệu', 'spx-express-woocommerce' ), self::parcel_source_label( $source ) );
		echo '</div>';
		if ( ! $valid && $errors ) {
			echo '<ul class="spx-readiness-list">';
			foreach ( $errors as $error ) { echo '<li>' . esc_html( (string) ( $error['message'] ?? __( 'Kiện hàng chưa hợp lệ.', 'spx-express-woocommerce' ) ) ) . '</li>'; }
			echo '</ul>';
		}
		echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết kỹ thuật', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		self::detail( __( 'Phiên bản chính sách kiện hàng', 'spx-express-woocommerce' ), $version );
		self::detail( __( 'Cân nặng (kg)', 'spx-express-woocommerce' ), $weight_kg > 0 ? (string) $weight_kg : '' );
		self::detail( __( 'Kích thước (cm)', 'spx-express-woocommerce' ), ( $length > 0 && $width > 0 && $height > 0 ) ? sprintf( '%s|%s|%s', $length, $width, $height ) : '' );
		echo '</dl></div></details></section>';
	}

	private static function parcel_source_label( string $source ): string {
		$labels = array(
			'product'      => __( 'Từ sản phẩm', 'spx-express-woocommerce' ),
			'shop_default' => __( 'Từ cấu hình mặc định', 'spx-express-woocommerce' ),
			'mixed'        => __( 'Kết hợp sản phẩm và mặc định', 'spx-express-woocommerce' ),
			'none'         => __( 'Không có dữ liệu kiện hàng', 'spx-express-woocommerce' ),
		);
		return $labels[ $source ] ?? __( 'Không xác định', 'spx-express-woocommerce' );
	}

	private static function render_payment( WC_Order $order, array $payment ): void {
		$method = (string) ( $payment['payment_method'] ?? '' );
		$method_label = '' === $method ? __( 'Chưa có phương thức thanh toán', 'spx-express-woocommerce' ) : $order->get_payment_method_title();
		if ( '' === trim( (string) $method_label ) ) { $method_label = $method; }
		$cod_amount = $payment['cod_amount'] ?? null;
		$reason = sanitize_key( (string) ( $payment['reason'] ?? '' ) );

		echo '<section class="spx-order-section spx-payment-summary" aria-labelledby="spx-order-payment-title"><div class="spx-section-heading"><h3 id="spx-order-payment-title">' . esc_html__( 'Thanh toán và thu hộ', 'spx-express-woocommerce' ) . '</h3>';
		echo SPX_Admin_Settings_Page::status_badge( '', ! empty( $payment['ready_for_shipment'] ) ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Bị khóa', 'spx-express-woocommerce' ), ! empty( $payment['ready_for_shipment'] ) ? 'success' : 'warning' ) . '</div>';
		echo '<div class="spx-order-summary__grid">';
		self::summary_item( __( 'Phương thức thanh toán', 'spx-express-woocommerce' ), (string) $method_label );
		self::summary_item( __( 'Đã thanh toán', 'spx-express-woocommerce' ), ! empty( $payment['is_paid'] ) ? __( 'Có', 'spx-express-woocommerce' ) : __( 'Chưa', 'spx-express-woocommerce' ) );
		echo '<div class="spx-summary-item"><span>' . esc_html__( 'Tiền SPX cần thu hộ', 'spx-express-woocommerce' ) . '</span><strong>' . ( null === $cod_amount ? esc_html__( 'Chưa xác định', 'spx-express-woocommerce' ) : wp_kses_post( wc_price( $cod_amount ) ) ) . '</strong></div>';
		echo '<div class="spx-summary-item"><span>' . esc_html__( 'Phí vận chuyển', 'spx-express-woocommerce' ) . '</span><strong>' . wp_kses_post( wc_price( $order->get_shipping_total() ) ) . '</strong></div>';
		if ( '' !== $reason ) { self::summary_item( __( 'Lý do khóa', 'spx-express-woocommerce' ), SPX_Shipment_Readiness::payment_message( $reason ) ); }
		echo '</div></section>';
	}

	private static function render_rate( WC_Order $order ): void {
		$source = (string) $order->get_meta( '_spx_rate_source', true );
		$quoted = (string) $order->get_meta( '_spx_rate_quoted_at', true );
		echo '<section class="spx-order-section spx-rate-summary" aria-labelledby="spx-order-rate-title"><div class="spx-section-heading"><h3 id="spx-order-rate-title">' . esc_html__( 'Phí vận chuyển', 'spx-express-woocommerce' ) . '</h3>';
		if ( 'spx_dynamic' === $source ) { echo SPX_Admin_Settings_Page::status_badge( '', __( 'Sandbox thử nghiệm', 'spx-express-woocommerce' ), 'warning' ); }
		echo '</div><div class="spx-order-summary__grid">';
		echo '<div class="spx-summary-item"><span>' . esc_html__( 'Phí WooCommerce đang áp dụng', 'spx-express-woocommerce' ) . '</span><strong>' . wp_kses_post( wc_price( $order->get_shipping_total() ) ) . '</strong></div>';
		self::summary_item( __( 'Nguồn phí', 'spx-express-woocommerce' ), SPX_Admin_Rate::checkout_source_label( $source ) );
		if ( '' !== $quoted ) { self::summary_item( __( 'Thời điểm báo giá', 'spx-express-woocommerce' ), $quoted ); }
		echo '</div>';
		if ( 'spx_dynamic' === $source ) { echo '<p class="spx-rate-warning"><strong>' . esc_html__( 'Phí động chỉ dùng cho Sandbox. Đơn vị phí chưa được SPX xác nhận và hệ số 1000 vẫn là thử nghiệm.', 'spx-express-woocommerce' ) . '</strong></p>'; }
		echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết kỹ thuật', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		$rows = array(
			__( 'Phí dự kiến (raw)', 'spx-express-woocommerce' ) => '_spx_rate_raw_estimated',
			__( 'Phí cơ bản (raw)', 'spx-express-woocommerce' ) => '_spx_rate_raw_basic',
			__( 'VAT (raw)', 'spx-express-woocommerce' ) => '_spx_rate_raw_vat',
			__( 'Hệ số thử nghiệm', 'spx-express-woocommerce' ) => '_spx_rate_multiplier',
			__( 'Kết quả cache', 'spx-express-woocommerce' ) => '_spx_rate_cache_hit',
			__( 'Phiên bản hợp đồng', 'spx-express-woocommerce' ) => '_spx_rate_contract_version',
			__( 'Lý do dùng phí dự phòng', 'spx-express-woocommerce' ) => '_spx_rate_fallback_reason',
		);
		foreach ( $rows as $label => $key ) { self::detail( $label, (string) $order->get_meta( $key, true ) ); }
		echo '</dl></div></details></section>';
	}

	private static function render_status( WC_Order $order ): void {
		$code = (string) $order->get_meta( '_spx_shipment_status_code', true );
		$mapped = '' !== $code ? SPX_Tracking_Status_Mapper::map( $code ) : array( 'customer_label' => __( 'Chưa có trạng thái', 'spx-express-woocommerce' ) );
		$mapping = SPX_Woo_Status_Mapping_Policy::mapping( $code );
		$result = sanitize_key( (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_RESULT, true ) );
		$source = sanitize_key( (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_SOURCE, true ) );
		echo '<section class="spx-order-section" aria-labelledby="spx-order-status-title"><h3 id="spx-order-status-title">' . esc_html__( 'Trạng thái và hành trình', 'spx-express-woocommerce' ) . '</h3><div class="spx-order-summary__grid">';
		self::summary_item( __( 'Trạng thái SPX', 'spx-express-woocommerce' ), (string) $mapped['customer_label'] );
		self::summary_item( __( 'Trạng thái WooCommerce', 'spx-express-woocommerce' ), wc_get_order_status_name( $order->get_status() ) );
		self::summary_item( __( 'Mapping tương ứng', 'spx-express-woocommerce' ), empty( $mapping['target'] ) ? __( 'Không thay đổi', 'spx-express-woocommerce' ) : wc_get_order_status_name( $mapping['target'] ) );
		self::summary_item( __( 'Kết quả gần nhất', 'spx-express-woocommerce' ), SPX_Admin_Status_Mapping::result_label( $result ) );
		echo '</div><details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết kỹ thuật', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		self::detail( __( 'Mã trạng thái SPX', 'spx-express-woocommerce' ), $code );
		self::detail( __( 'Nguồn cập nhật', 'spx-express-woocommerce' ), SPX_Admin_Status_Mapping::source_label( $source ) );
		self::detail( __( 'Lần xử lý gần nhất', 'spx-express-woocommerce' ), (string) $order->get_meta( SPX_Woo_Status_Mapping_Service::M_AT, true ) );
		echo '</dl></div></details></section>';
	}

	private static function render_actions( WC_Order $order, array $actions ): void {
		echo '<section class="spx-order-section" aria-labelledby="spx-order-actions-title"><h3 id="spx-order-actions-title">' . esc_html__( 'Hành động', 'spx-express-woocommerce' ) . '</h3>';
		$cancel_notice = isset( $_GET['spx_cancel_notice'] ) ? sanitize_key( wp_unslash( $_GET['spx_cancel_notice'] ) ) : '';
		if ( '' !== $cancel_notice && class_exists( 'SPX_Admin_Notice_Presenter' ) ) {
			$mapped = SPX_Admin_Notice_Presenter::cancel_notice( $cancel_notice );
			if ( $mapped ) { echo '<div class="notice notice-' . esc_attr( $mapped[0] ) . ' inline"><p>' . esc_html( $mapped[1] ) . '</p></div>'; }
		}
		echo '<div class="spx-action-group">';
		if ( $actions['sandbox_create'] ) { self::woo_action_button( SPX_Admin_Shipment::CREATE, __( 'Tạo vận đơn SPX Sandbox', 'spx-express-woocommerce' ), 'button button-primary' ); }
		if ( $actions['production_create'] ) { echo '<button type="button" class="button button-primary" disabled aria-disabled="true">' . esc_html__( 'Tạo vận đơn SPX', 'spx-express-woocommerce' ) . '</button>'; }
		elseif ( $actions['production_locked'] ) { echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html__( 'Tạo vận đơn SPX — Production chưa xác minh', 'spx-express-woocommerce' ) . '</button>'; }
		$link = (string) $order->get_meta( '_spx_tracking_link', true );
		if ( $actions['tracking_link'] && self::safe_https( $link ) ) { echo '<a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Mở tracking', 'spx-express-woocommerce' ) . '</a>'; }
		if ( $actions['label'] ) { self::label_button( $order ); }
		if ( $actions['sync'] ) { self::woo_action_button( SPX_Admin_Shipment::SYNC, __( 'Đồng bộ ngay', 'spx-express-woocommerce' ), 'button' ); }
		echo '</div>';
		if ( $actions['cancel'] ) {
			echo '<div class="spx-danger-zone"><p><strong>' . esc_html__( 'Hủy vận đơn SPX không hủy đơn WooCommerce và không hoàn tiền.', 'spx-express-woocommerce' ) . '</strong></p>';
			echo wp_nonce_field( SPX_Admin_Cancel::NONCE . '_' . $order->get_id(), 'spx_cancel_nonce', true, false ) . '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '"><button type="submit" class="button spx-danger-button" name="action" value="spx_cancel_shipment" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post" data-spx-confirm="' . esc_attr__( 'Bạn sắp hủy vận đơn SPX. Đơn WooCommerce sẽ không bị hủy và không được hoàn tiền. Tiếp tục?', 'spx-express-woocommerce' ) . '">' . esc_html__( 'Hủy vận đơn SPX', 'spx-express-woocommerce' ) . '</button></div>';
		} elseif ( $actions['has_tracking'] ) {
			$note = empty( $actions['cancel_enabled'] )
				? __( 'Chức năng hủy vận đơn chưa được kích hoạt.', 'spx-express-woocommerce' )
				: __( 'Vận đơn hiện không còn đủ điều kiện hủy trên SPX.', 'spx-express-woocommerce' );
			echo '<p class="spx-action-note">' . esc_html( $note ) . '</p>';
		}
		if ( $actions['mock_create'] ) {
			echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Công cụ phát triển', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><p>' . SPX_Admin_Settings_Page::status_badge( '', __( 'Chỉ dùng nội bộ', 'spx-express-woocommerce' ), 'warning' ) . '</p>';
			self::woo_action_button( 'spx_create_shipment', __( 'Tạo vận đơn giả lập', 'spx-express-woocommerce' ), 'button' );
			echo '</div></details>';
		}
		echo '<details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết kỹ thuật', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><dl class="spx-definition-list">';
		self::detail( __( 'Môi trường nội bộ', 'spx-express-woocommerce' ), SPX_Order_Environment::get( $order ) );
		self::detail( __( 'Mã đơn nội bộ', 'spx-express-woocommerce' ), (string) $order->get_meta( '_spx_client_order_id', true ) );
		self::detail( __( 'Trạng thái tạo vận đơn', 'spx-express-woocommerce' ), self::create_state_label( (string) $order->get_meta( '_spx_create_state', true ) ) );
		self::detail( __( 'Lần thử gần nhất', 'spx-express-woocommerce' ), (string) $order->get_meta( '_spx_create_attempted_at', true ) );
		echo '</dl></div></details></section>';
	}

	private static function woo_action_button( string $action, string $label, string $class ): void {
		echo '<button type="submit" class="' . esc_attr( $class ) . '" name="wc_order_action" value="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
	}

	private static function label_button( WC_Order $order ): void {
		$cached = ( new SPX_Label_Manager() )->cached_for_order( $order->get_id() );
		if ( is_array( $cached ) && self::safe_https( (string) ( $cached['url'] ?? '' ) ) ) {
			echo '<a class="button" href="' . esc_url( $cached['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Mở nhãn SPX', 'spx-express-woocommerce' ) . '</a>';
			return;
		}
		echo wp_nonce_field( SPX_Admin_Label::NONCE . '_' . $order->get_id(), 'spx_label_nonce', true, false ) . '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '"><button type="submit" class="button" name="action" value="' . esc_attr( SPX_Admin_Label::ACTION ) . '" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post">' . esc_html__( 'Lấy/In nhãn SPX', 'spx-express-woocommerce' ) . '</button>';
	}

	private static function action_context( WC_Order $order, array $readiness ): array {
		$tracking = (string) $order->get_meta( '_spx_tracking_number', true );
		$client = (string) $order->get_meta( '_spx_client_order_id', true );
		$environment = SPX_Order_Environment::get( $order );
		$state = SPX_Production_State::normalize( get_option( 'spx_production_state', 'disabled' ) );
		$production_verified = in_array( $state, array( SPX_Production_State::VERIFIED, SPX_Production_State::ENABLED ), true ) && SPX_Production_Readiness::current()['ready'];
		$real = SPX_Tracking_Service::is_real_tracking( $tracking );
		$shipment_status = (string) $order->get_meta( '_spx_shipment_status', true );
		$cancel_state = (string) $order->get_meta( '_spx_cancel_state', true );
		return array(
			'wp_environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'production_requested'=> SPX_Production_State::DISABLED !== $state,
			'sandbox_configured' => SPX_API_Config::for_test()->has_account_credentials(),
			'ready'              => ! empty( $readiness['ready'] ),
			'has_tracking'       => $real,
			'has_client_id'      => '' !== $client,
			'production_verified'=> $production_verified,
			'label_eligible'     => $real && 'test' === $environment && 'cancelled' !== $shipment_status,
			'cancel_enabled'     => defined( 'SPX_ENABLE_REAL_CANCEL' ) && true === SPX_ENABLE_REAL_CANCEL,
			'cancel_eligible'    => $real && 'test' === $environment && '1001' === (string) $order->get_meta( '_spx_shipment_status_code', true ) && ! in_array( $cancel_state, array( 'succeeded', 'unknown' ), true ),
		);
	}

	/** Pure presentation policy, intentionally independent from handler execution. */
	public static function action_visibility( array $context ): array {
		$wp_environment = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $context['wp_environment'] ?? 'production' ) ) );
		$has_tracking = ! empty( $context['has_tracking'] );
		$has_client = ! empty( $context['has_client_id'] );
		$is_development = in_array( $wp_environment, array( 'local', 'development' ), true );
		$production_requested = ! empty( $context['production_requested'] );
		$production_verified = ! empty( $context['production_verified'] );
		return array(
			'mock_create'      => $is_development && ! $has_tracking && ! $has_client,
			'sandbox_create'   => ! $production_requested && ! empty( $context['sandbox_configured'] ) && ! empty( $context['ready'] ) && ! $has_tracking && ! $has_client,
			'production_create'=> $production_requested && $production_verified && ! empty( $context['ready'] ) && ! $has_tracking && ! $has_client,
			'production_locked'=> $production_requested && ! $production_verified && ! $has_tracking,
			'tracking_link'    => $has_tracking,
			'label'            => $has_tracking && ! empty( $context['label_eligible'] ),
			'sync'             => $has_tracking || $has_client,
			'cancel'           => $has_tracking && ! empty( $context['cancel_enabled'] ) && ! empty( $context['cancel_eligible'] ),
			'cancel_enabled'   => ! empty( $context['cancel_enabled'] ),
			'cancel_eligible'  => ! empty( $context['cancel_eligible'] ),
			'has_tracking'     => $has_tracking,
		);
	}

	private static function create_state_label( string $state ): string {
		$labels = array(
			''                     => __( 'Chưa tạo', 'spx-express-woocommerce' ),
			'attempting'           => __( 'Đang gửi yêu cầu', 'spx-express-woocommerce' ),
			'created'              => __( 'Đã tạo', 'spx-express-woocommerce' ),
			'unknown'              => __( 'Chưa xác định', 'spx-express-woocommerce' ),
			'duplicate_unresolved' => __( 'Cần đồng bộ lại', 'spx-express-woocommerce' ),
			'failed'               => __( 'Tạo không thành công', 'spx-express-woocommerce' ),
		);
		return $labels[ $state ] ?? __( 'Chưa xác định', 'spx-express-woocommerce' );
	}

	private static function detail( string $label, string $value ): void {
		if ( '' !== $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
	}

	private static function safe_https( string $url ): bool {
		return '' !== $url && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	}
}
