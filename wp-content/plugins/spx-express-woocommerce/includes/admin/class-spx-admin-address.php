<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin surface for SPX address dataset import, sender profile, the
 * province/district/ward dependent dropdowns (via AJAX), and the per-order SPX
 * recipient address override + shipment readiness panel. All writes are guarded
 * by capability + nonce. No Order API is ever called here.
 */
final class SPX_Admin_Address {
	const CAP        = 'manage_woocommerce';
	const PAGE       = 'spx-express-address';
	const NONCE_SAVE = 'spx_address_save';
	const NONCE_AJAX = 'spx_address_ajax';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_spx_import_dataset', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_spx_save_sender', array( __CLASS__, 'handle_sender' ) );
		add_action( 'admin_post_spx_save_parcel', array( __CLASS__, 'handle_parcel' ) );
		add_action( 'wp_ajax_spx_get_districts', array( __CLASS__, 'ajax_districts' ) );
		add_action( 'wp_ajax_spx_get_wards', array( __CLASS__, 'ajax_wards' ) );	
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_override' ), 20, 1 );
	}

	private static function repo(): SPX_Address_Repository {
		return new SPX_Address_Repository();
	}

	/* ---- Settings page --------------------------------------------------- */

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'SPX Express', 'spx-express-woocommerce' ), __( 'SPX Express', 'spx-express-woocommerce' ), self::CAP, self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		SPX_Admin_Settings_Page::render();
	}

	public static function render_dataset_settings(): void {
		$repo = self::repo();
		$meta = $repo->get_dataset_metadata();
		echo '<section aria-labelledby="spx-address-data-title"><h2 id="spx-address-data-title">' . esc_html__( 'Dữ liệu địa chỉ', 'spx-express-woocommerce' ) . '</h2>';
		echo '<article class="spx-admin-card"><div class="spx-card-heading"><h3>' . esc_html__( 'Dataset SPX', 'spx-express-woocommerce' ) . '</h3>';
		echo SPX_Admin_Settings_Page::status_badge( '', $repo->is_available() ? __( 'Sẵn sàng', 'spx-express-woocommerce' ) : __( 'Cần cấu hình', 'spx-express-woocommerce' ), $repo->is_available() ? 'success' : 'warning' ) . '</div>';
		if ( $repo->is_available() ) {
			echo '<div class="spx-admin-grid spx-admin-grid--stats">';
			self::dataset_stat( __( 'Phiên bản dataset', 'spx-express-woocommerce' ), (string) ( $meta['version'] ?? '—' ) );
			self::dataset_stat( __( 'Ngày import', 'spx-express-woocommerce' ), (string) ( $meta['imported_at'] ?? '—' ) );
			self::dataset_stat( __( 'Tỉnh/Thành phố', 'spx-express-woocommerce' ), (string) ( $meta['province_count'] ?? '0' ) );
			self::dataset_stat( __( 'Quận/Huyện', 'spx-express-woocommerce' ), (string) ( $meta['district_count'] ?? '0' ) );
			self::dataset_stat( __( 'Phường/Xã', 'spx-express-woocommerce' ), (string) ( $meta['ward_count'] ?? $meta['record_count'] ?? '0' ) );
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'Chưa có dữ liệu địa chỉ. Hãy nhập file khu vực phục vụ chính thức của SPX.', 'spx-express-woocommerce' ) . '</p>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spx-upload-form">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="spx_import_dataset">';
		echo '<label for="spx_dataset"><strong>' . esc_html__( 'File dữ liệu SPX (.xlsx)', 'spx-express-woocommerce' ) . '</strong></label>';
		echo '<input id="spx_dataset" type="file" name="spx_dataset" accept=".xlsx" required>';
		submit_button( __( 'Nhập dữ liệu địa chỉ', 'spx-express-woocommerce' ), 'secondary', 'submit', false );
		echo '</form><details class="spx-technical-details"><summary>' . esc_html__( 'Chi tiết dữ liệu', 'spx-express-woocommerce' ) . '</summary><div class="spx-technical-details__body"><p>' . esc_html__( 'File được kiểm tra đầy đủ trước khi thay thế dataset hiện tại. Nếu import lỗi, dữ liệu cũ vẫn được giữ nguyên.', 'spx-express-woocommerce' ) . '</p></div></details></article></section>';
	}

	private static function dataset_stat( string $label, string $value ): void {
		echo '<div class="spx-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
	}

	public static function render_sender_settings(): void {
		$repo = self::repo();
		$profile = SPX_Sender_Profile::get();
		echo '<section aria-labelledby="spx-sender-title"><h2 id="spx-sender-title">' . esc_html__( 'Hồ sơ người gửi', 'spx-express-woocommerce' ) . '</h2><article class="spx-admin-card">';
		echo '<div class="spx-card-heading"><h3>' . esc_html__( 'Thông tin lấy hàng', 'spx-express-woocommerce' ) . '</h3>' . SPX_Admin_Settings_Page::status_badge( '', SPX_Sender_Profile::is_complete() ? __( 'Địa chỉ hợp lệ', 'spx-express-woocommerce' ) : __( 'Chưa đủ thông tin', 'spx-express-woocommerce' ), SPX_Sender_Profile::is_complete() ? 'success' : 'warning' ) . '</div>';
		if ( ! $repo->is_available() ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Hãy nhập dữ liệu địa chỉ trước khi chọn khu vực người gửi.', 'spx-express-woocommerce' ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="spx_save_sender"><div class="spx-form-grid" data-spx-location-picker>';
		self::text_field( 'sender_name', __( 'Tên cửa hàng/người gửi', 'spx-express-woocommerce' ), $profile['sender_name'], 64 );
		self::text_field( 'sender_phone', __( 'Số điện thoại', 'spx-express-woocommerce' ), $profile['sender_phone'], 32, 'tel' );
		self::text_field( 'sender_email', __( 'Email (tùy chọn)', 'spx-express-woocommerce' ), $profile['sender_email'], 64, 'email' );
		self::text_field( 'sender_detail_address', __( 'Địa chỉ chi tiết', 'spx-express-woocommerce' ), $profile['sender_detail_address'], 256 );
		self::location_fields( $repo, $profile );
		echo '</div><fieldset class="spx-choice-group"><legend>' . esc_html__( 'Hình thức gửi', 'spx-express-woocommerce' ) . '</legend>';
		echo '<label for="spx_collect_pickup"><input id="spx_collect_pickup" type="radio" disabled aria-disabled="true"> ' . esc_html__( 'SPX đến lấy', 'spx-express-woocommerce' ) . '</label>';
		echo '<label for="spx_collect_dropoff"><input id="spx_collect_dropoff" type="radio" checked disabled aria-disabled="true"> ' . esc_html__( 'Shop mang tới điểm gửi', 'spx-express-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Tích hợp hiện sử dụng hình thức shop mang tới điểm gửi.', 'spx-express-woocommerce' ) . '</p></fieldset>';
		submit_button( __( 'Lưu hồ sơ người gửi', 'spx-express-woocommerce' ) );
		echo '</form></article></section>';
	}

	private static function text_field( string $name, string $label, string $value, int $maxlen, string $type = 'text' ): void {
		echo '<div class="spx-form-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="' . esc_attr( (string) $maxlen ) . '"></div>';
	}

	private static function location_fields( SPX_Address_Repository $repo, array $profile ): void {
		$provinces = $repo->is_available() ? $repo->get_provinces() : array();
		$districts = $repo->is_available() && $profile['sender_province_code'] ? $repo->get_districts( $profile['sender_province_code'] ) : array();
		$wards = $repo->is_available() && $profile['sender_district_code'] ? $repo->get_wards( $profile['sender_district_code'] ) : array();
		self::location_field( 'sender_province_code', __( 'Tỉnh/Thành phố', 'spx-express-woocommerce' ), $provinces, $profile['sender_province_code'] );
		self::location_field( 'sender_district_code', __( 'Quận/Huyện', 'spx-express-woocommerce' ), $districts, $profile['sender_district_code'] );
		self::location_field( 'sender_ward_code', __( 'Phường/Xã', 'spx-express-woocommerce' ), $wards, $profile['sender_ward_code'] );
	}

	private static function location_field( string $id, string $label, array $items, string $selected ): void {
		echo '<div class="spx-form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '"><option value="">—</option>';
		foreach ( $items as $item ) { echo '<option value="' . esc_attr( $item['code'] ) . '"' . selected( $selected, $item['code'], false ) . '>' . esc_html( $item['name'] ) . '</option>'; }
		echo '</select></div>';
	}

	private static function text_row( string $name, string $label, string $value, int $maxlen ) {
		echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="' . esc_attr( (string) $maxlen ) . '" /></td></tr>';
	}

	private static function location_rows( SPX_Address_Repository $repo, array $profile ) {
		$provinces = $repo->is_available() ? $repo->get_provinces() : array();
		echo '<tr><th>' . esc_html__( 'Province', 'spx-express-woocommerce' ) . '</th><td><select id="sender_province_code" name="sender_province_code"><option value="">—</option>';
		foreach ( $provinces as $p ) { echo '<option value="' . esc_attr( $p['code'] ) . '"' . selected( $profile['sender_province_code'], $p['code'], false ) . '>' . esc_html( $p['name'] ) . '</option>'; }
		echo '</select></td></tr>';
		$districts = ( $repo->is_available() && $profile['sender_province_code'] ) ? $repo->get_districts( $profile['sender_province_code'] ) : array();
		echo '<tr><th>' . esc_html__( 'District', 'spx-express-woocommerce' ) . '</th><td><select id="sender_district_code" name="sender_district_code"><option value="">—</option>';
		foreach ( $districts as $d ) { echo '<option value="' . esc_attr( $d['code'] ) . '"' . selected( $profile['sender_district_code'], $d['code'], false ) . '>' . esc_html( $d['name'] ) . '</option>'; }
		echo '</select></td></tr>';
		$wards = ( $repo->is_available() && $profile['sender_district_code'] ) ? $repo->get_wards( $profile['sender_district_code'] ) : array();
		echo '<tr><th>' . esc_html__( 'Ward', 'spx-express-woocommerce' ) . '</th><td><select id="sender_ward_code" name="sender_ward_code"><option value="">—</option>';
		foreach ( $wards as $w ) { echo '<option value="' . esc_attr( $w['code'] ) . '"' . selected( $profile['sender_ward_code'], $w['code'], false ) . '>' . esc_html( $w['name'] ) . '</option>'; }
		echo '</select></td></tr>';
	}

	/* ---- POST handlers --------------------------------------------------- */

	public static function handle_import() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Permission denied.', 'spx-express-woocommerce' ) ); }
		check_admin_referer( self::NONCE_SAVE );
		$notice = 'import_failed';
		if ( isset( $_FILES['spx_dataset'] ) && is_array( $_FILES['spx_dataset'] ) && empty( $_FILES['spx_dataset']['error'] ) ) {
			$tmp  = isset( $_FILES['spx_dataset']['tmp_name'] ) ? $_FILES['spx_dataset']['tmp_name'] : '';
			$name = isset( $_FILES['spx_dataset']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['spx_dataset']['name'] ) ) : '';
			$size = isset( $_FILES['spx_dataset']['size'] ) ? (int) $_FILES['spx_dataset']['size'] : 0;
			if ( $tmp && is_uploaded_file( $tmp ) && preg_match( '/\.xlsx$/i', $name ) && $size > 0 && $size <= 30 * 1024 * 1024 ) {
				$result = ( new SPX_Address_Import_Service() )->import_from_file( $tmp );
				if ( $result['ok'] ) {
					$notice = 'imported';
					SPX_Logger::log( 'info', 'address_dataset_imported', 0, '', 'records=' . ( $result['meta']['record_count'] ?? 0 ) );
				} else {
					SPX_Logger::log( 'error', 'address_dataset_import_failed', 0, '', substr( wp_json_encode( array_slice( $result['errors'], 0, 3 ) ), 0, 300 ) );
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'spx_notice', $notice, SPX_Admin_Settings_Page::tab_url( 'addresses' ) ) );
		exit;
	}

	public static function handle_sender() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Permission denied.', 'spx-express-woocommerce' ) ); }
		check_admin_referer( self::NONCE_SAVE );
		$fields = array( 'sender_name', 'sender_phone', 'sender_detail_address', 'sender_province_code', 'sender_district_code', 'sender_ward_code', 'sender_email' );
		$input  = array();
		foreach ( $fields as $f ) { $input[ $f ] = isset( $_POST[ $f ] ) ? wp_unslash( $_POST[ $f ] ) : ''; }
		$result = SPX_Sender_Profile::save( $input, self::repo() );
		wp_safe_redirect( add_query_arg( 'spx_notice', $result['ok'] ? 'sender_saved' : 'sender_error', SPX_Admin_Settings_Page::tab_url( 'sender' ) ) );
		exit;
	}

	/**
	 * Save the default parcel dimensions/weight and the missing-data policy. Values
	 * are validated to positive numbers within SPX limits; zero/empty means "unset".
	 * Never stores negative values.
	 */
	public static function handle_parcel() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Permission denied.', 'spx-express-woocommerce' ) ); }
		check_admin_referer( self::NONCE_SAVE );
		$clean = SPX_Parcel_Builder::sanitize_defaults_input( array(
			'parcel_weight_grams' => wc_format_decimal( wp_unslash( $_POST['parcel_weight_grams'] ?? '0' ) ),
			'parcel_length_cm'    => wc_format_decimal( wp_unslash( $_POST['parcel_length_cm'] ?? '0' ) ),
			'parcel_width_cm'     => wc_format_decimal( wp_unslash( $_POST['parcel_width_cm'] ?? '0' ) ),
			'parcel_height_cm'    => wc_format_decimal( wp_unslash( $_POST['parcel_height_cm'] ?? '0' ) ),
			'parcel_policy'       => sanitize_key( wp_unslash( $_POST['parcel_policy'] ?? '' ) ),
		) );
		update_option( SPX_Parcel_Builder::OPTION_DEFAULTS, $clean['defaults'], false );
		update_option( SPX_Parcel_Builder::OPTION_POLICY, $clean['policy'], false );
		wp_safe_redirect( add_query_arg( 'spx_notice', 'parcel_saved', SPX_Admin_Settings_Page::tab_url( 'rates' ) ) );
		exit;
	}

	/* ---- AJAX dependent dropdowns --------------------------------------- */

	public static function ajax_districts() {
		self::ajax_guard();
		$pcode = isset( $_POST['province_code'] ) ? sanitize_text_field( wp_unslash( $_POST['province_code'] ) ) : '';
		wp_send_json_success( self::repo()->get_districts( $pcode ) );
	}

	public static function ajax_wards() {
		self::ajax_guard();
		$dcode = isset( $_POST['district_code'] ) ? sanitize_text_field( wp_unslash( $_POST['district_code'] ) ) : '';
		$wards = array();
		foreach ( self::repo()->get_wards( $dcode ) as $w ) { $wards[] = array( 'code' => $w['code'], 'name' => $w['name'] ); }
		wp_send_json_success( $wards );
	}

	private static function ajax_guard() {
		if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( 'forbidden', 403 ); }
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
	}

	/* ---- Per-order override + readiness --------------------------------- */

	public static function render_order_panel( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( self::CAP ) ) { return; }
		$repo = self::repo();
		echo '<div class="spx-order-address"><h3>' . esc_html__( 'Địa chỉ SPX', 'spx-express-woocommerce' ) . '</h3>';
		if ( ! $repo->is_available() ) {
			echo '<p><em>' . esc_html__( 'Import the SPX address dataset to map this order.', 'spx-express-woocommerce' ) . '</em></p></div>';
			return;
		}
		$ov        = SPX_Order_Address::get( $order );
		$snapshot  = SPX_Checkout_Address_Snapshot::read_from_order( $order );
		$effective = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
		echo '<div class="spx-address-diagnostics">';
		echo '<p><strong>' . esc_html__( 'Nguồn địa chỉ', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( SPX_Checkout_Address_Snapshot::source_label( $effective['source'] ) ) . '</p>';
		if ( SPX_Checkout_Address_Snapshot::is_complete( $snapshot ) ) {
			echo '<p><strong>' . esc_html__( 'Checkout snapshot', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( $snapshot['province_name'] . ' / ' . $snapshot['district_name'] . ' / ' . $snapshot['ward_name'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'SPX IDs', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( $snapshot['province_id'] . ' / ' . $snapshot['district_id'] . ' / ' . $snapshot['ward_id'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Capabilities', 'spx-express-woocommerce' ) . ':</strong> Delivery=' . esc_html( $snapshot['delivery_supported'] ? 'Y' : 'N' ) . ', COD=' . esc_html( $snapshot['cod_supported'] ? 'Y' : 'N' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Dataset / validated', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( $snapshot['dataset_version'] . ' / ' . $snapshot['validated_at'] ) . '</p>';
		}
		echo '</div>';
		$provinces = $repo->get_provinces();
		$districts = $ov['province_code'] ? $repo->get_districts( $ov['province_code'] ) : array();
		$wards     = $ov['district_code'] ? $repo->get_wards( $ov['district_code'] ) : array();
		wp_nonce_field( self::NONCE_SAVE, 'spx_order_nonce' );
		echo '<p><label>' . esc_html__( 'Province', 'spx-express-woocommerce' ) . '<br /><select id="spx_o_province_code" name="spx_o_province_code"><option value="">—</option>';
		foreach ( $provinces as $p ) { echo '<option value="' . esc_attr( $p['code'] ) . '"' . selected( $ov['province_code'], $p['code'], false ) . '>' . esc_html( $p['name'] ) . '</option>'; }
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'District', 'spx-express-woocommerce' ) . '<br /><select id="spx_o_district_code" name="spx_o_district_code"><option value="">—</option>';
		foreach ( $districts as $d ) { echo '<option value="' . esc_attr( $d['code'] ) . '"' . selected( $ov['district_code'], $d['code'], false ) . '>' . esc_html( $d['name'] ) . '</option>'; }
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Ward', 'spx-express-woocommerce' ) . '<br /><select id="spx_o_ward_code" name="spx_o_ward_code"><option value="">—</option>';
		foreach ( $wards as $w ) { echo '<option value="' . esc_attr( $w['code'] ) . '"' . selected( $ov['ward_code'], $w['code'], false ) . '>' . esc_html( $w['name'] ) . '</option>'; }
		echo '</select></label></p>';

		self::render_readiness( $order, $repo );
		echo '</div>';
		self::dropdown_script( 'spx_o_province_code', 'spx_o_district_code', 'spx_o_ward_code' );
	}

	private static function render_readiness( WC_Order $order, SPX_Address_Repository $repo ) {
		$resolution = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
		$profile    = SPX_Sender_Profile::get();
		$payload    = SPX_Order_Mapper::build( $order, SPX_Settings::get_instance_settings() );
		$ctx        = array(
			'sender'    => $profile,
			'recipient' => array(
				'name'    => $payload['recipient']['name'] ?? '',
				'phone'   => $payload['recipient']['phone'] ?? '',
				'address' => $payload['recipient']['address'] ?? '',
				'province_code' => $resolution['province_code'], 'district_code' => $resolution['district_code'], 'ward_code' => $resolution['ward_code'],
			),
			'parcel'    => array( 'weight_grams' => $payload['weight_grams'] ?? 0, 'item_quantity' => 1 ),
			'payment'   => isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : SPX_Payment_Resolver::resolve( $order ),
		);
		$ward = ! empty( $resolution['district_code'] ) && ! empty( $resolution['ward_code'] ) ? $repo->get_ward( $resolution['district_code'], $resolution['ward_code'] ) : null;
		if ( $ward ) {
			$ctx['recipient']['delivery_supported'] = ! empty( $ward['delivery'] );
			$ctx['recipient']['cod_supported'] = ! empty( $ward['cod'] );
			$ctx['recipient']['service_status'] = (string) $ward['status'];
		}
		$ready = SPX_Shipment_Readiness::evaluate( $ctx );
		if ( $ready['ready'] ) {
			echo '<p style="color:#1a7f37;"><strong>' . esc_html__( 'Sẵn sàng tạo vận đơn SPX', 'spx-express-woocommerce' ) . '</strong></p>';
		} else {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html__( 'Chưa đủ điều kiện tạo vận đơn SPX:', 'spx-express-woocommerce' ) . '</strong></p><ul style="list-style:disc;margin-left:18px;">';
			foreach ( $ready['errors'] as $e ) { echo '<li>' . esc_html( $e['message'] ) . '</li>'; }
			echo '</ul>';
		}
	}

	public static function save_order_override( $order_id ) {
		if ( ! current_user_can( self::CAP ) ) { return; }
		if ( ! isset( $_POST['spx_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spx_order_nonce'] ) ), self::NONCE_SAVE ) ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		$pcode = isset( $_POST['spx_o_province_code'] ) ? sanitize_text_field( wp_unslash( $_POST['spx_o_province_code'] ) ) : '';
		$dcode = isset( $_POST['spx_o_district_code'] ) ? sanitize_text_field( wp_unslash( $_POST['spx_o_district_code'] ) ) : '';
		$wcode = isset( $_POST['spx_o_ward_code'] ) ? sanitize_text_field( wp_unslash( $_POST['spx_o_ward_code'] ) ) : '';
		$res   = SPX_Order_Address::save( $order, $pcode, $dcode, $wcode, self::repo(), 'admin_override' );
		if ( ! $res['ok'] ) {
			$order->add_order_note( __( 'SPX address override was not saved: invalid province/district/ward.', 'spx-express-woocommerce' ) );
		}
	}

	/* ---- Shared dependent-dropdown script ------------------------------- */

	private static function dropdown_script( string $prov_id, string $dist_id, string $ward_id ) {
		$nonce = wp_create_nonce( self::NONCE_AJAX );
		$ajax  = admin_url( 'admin-ajax.php' );
		?>
<script>
(function() {
  var nonce = <?php echo wp_json_encode( $nonce ); ?>,
    ajaxUrl = <?php echo wp_json_encode( $ajax ); ?>;
  var prov = document.getElementById(<?php echo wp_json_encode( $prov_id ); ?>);
  var dist = document.getElementById(<?php echo wp_json_encode( $dist_id ); ?>);
  var ward = document.getElementById(<?php echo wp_json_encode( $ward_id ); ?>);
  if (!prov || !dist || !ward) {
    return;
  }

  function fill(sel, items) {
    sel.innerHTML = '<option value="">—</option>';
    items.forEach(function(i) {
      var o = document.createElement('option');
      o.value = i.code;
      o.textContent = i.name;
      sel.appendChild(o);
    });
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', nonce);
    Object.keys(data).forEach(function(k) {
      body.append(k, data[k]);
    });
    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString()
    }).then(function(r) {
      return r.json();
    });
  }
  prov.addEventListener('change', function() {
    fill(dist, []);
    fill(ward, []);
    if (!prov.value) {
      return;
    }
    post('spx_get_districts', {
      province_code: prov.value
    }).then(function(j) {
      if (j && j.success) {
        fill(dist, j.data);
      }
    });
  });
  dist.addEventListener('change', function() {
    fill(ward, []);
    if (!dist.value) {
      return;
    }
    post('spx_get_wards', {
      district_code: dist.value
    }).then(function(j) {
      if (j && j.success) {
        fill(ward, j.data);
      }
    });
  });
})();
</script>
<?php
	}
}
