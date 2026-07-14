<?php
defined( 'ABSPATH' ) || exit;

/** Shipping-only SPX selectors and persistence for Classic Checkout. */
final class SPX_Classic_Checkout_Address {
	const SESSION_KEY = 'spx_shipping_address_selection';
	const CUSTOMER_KEY = '_spx_checkout_shipping_selection';

	public static function init(): void {
		add_action( 'woocommerce_checkout_before_order_review', array( __CLASS__, 'render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_posted_selection' ) );
	}

	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) { return; }
		$style_version = (string) filemtime( SPX_WC_PATH . 'assets/css/checkout-address.css' );
		$script_version = (string) filemtime( SPX_WC_PATH . 'assets/js/classic-checkout-address.js' );
		wp_enqueue_style( 'spx-checkout-address', SPX_WC_URL . 'assets/css/checkout-address.css', array(), $style_version );
		wp_enqueue_script( 'spx-classic-checkout-address', SPX_WC_URL . 'assets/js/classic-checkout-address.js', array( 'jquery' ), $script_version, true );
		wp_localize_script( 'spx-classic-checkout-address', 'spxCheckoutAddress', array(
			'restUrl' => esc_url_raw( rest_url( SPX_Checkout_Address_REST_Controller::NAMESPACE ) ),
			'spxMethod' => 'spx_express',
			'labels' => array( 'choose' => __( '— Chọn —', 'spx-express-woocommerce' ), 'loading' => __( 'Đang tải…', 'spx-express-woocommerce' ), 'error' => __( 'Không thể tải địa chỉ SPX.', 'spx-express-woocommerce' ) ),
		) );
	}

	public static function render(): void {
		$service = new SPX_Checkout_Address_Service();
		$selected = self::prefill( $service );
		$provinces = self::options( $service->get_provinces() );
		$districts = empty( $selected['province_id'] ) ? array() : self::options( $service->get_districts( $selected['province_id'] ) );
		$wards = empty( $selected['district_id'] ) ? array() : self::options( $service->get_wards( $selected['province_id'], $selected['district_id'] ), true );
		echo '<section id="spx-shipping-address" class="spx-shipping-address" data-spx-required="' . esc_attr( SPX_Checkout_Eligibility::current_checkout_requires_selection() ? '1' : '0' ) . '">';
		echo '<h3>' . esc_html__( 'Địa chỉ giao hàng SPX', 'spx-express-woocommerce' ) . '</h3>';
		echo '<p class="spx-shipping-address__note">' . esc_html__( 'Chỉ dùng để xác định khu vực giao hàng SPX; địa chỉ WooCommerce của bạn vẫn được giữ nguyên.', 'spx-express-woocommerce' ) . '</p>';
		woocommerce_form_field( 'spx_shipping_province_id', array( 'type' => 'select', 'label' => __( 'Tỉnh/Thành phố SPX', 'spx-express-woocommerce' ), 'required' => true, 'options' => array( '' => __( '— Chọn —', 'spx-express-woocommerce' ) ) + $provinces ), $selected['province_id'] );
		woocommerce_form_field( 'spx_shipping_district_id', array( 'type' => 'select', 'label' => __( 'Quận/Huyện SPX', 'spx-express-woocommerce' ), 'required' => true, 'options' => array( '' => __( '— Chọn —', 'spx-express-woocommerce' ) ) + $districts ), $selected['district_id'] );
		woocommerce_form_field( 'spx_shipping_ward_id', array( 'type' => 'select', 'label' => __( 'Phường/Xã SPX', 'spx-express-woocommerce' ), 'required' => true, 'options' => array( '' => __( '— Chọn —', 'spx-express-woocommerce' ) ) + $wards ), $selected['ward_id'] );
		echo '<input type="hidden" name="spx_shipping_dataset_version" value="' . esc_attr( $service->get_dataset_version() ) . '" />';
		echo '<p class="spx-shipping-address__feedback" aria-live="polite"></p></section>';
	}

	public static function validate_checkout( array $data, WP_Error $errors ): void {
		if ( ! SPX_Checkout_Eligibility::current_checkout_requires_selection() ) { return; }
		$payload = self::payload_from_array( $_POST );
		$is_cod = isset( $data['payment_method'] ) && 'cod' === $data['payment_method'];
		$result = self::validate_payload( $payload, $is_cod, new SPX_Checkout_Address_Service() );
		if ( ! $result['valid'] ) { $errors->add( 'spx_shipping_address_' . $result['error'], self::error_message( $result['error'] ) ); }
	}

	public static function persist_order( WC_Order $order, array $data ): void {
		if ( ! SPX_Checkout_Eligibility::current_checkout_requires_selection() ) { return; }
		$result = self::validate_payload( self::payload_from_array( $_POST ), isset( $data['payment_method'] ) && 'cod' === $data['payment_method'], new SPX_Checkout_Address_Service() );
		if ( empty( $result['valid'] ) ) { return; }
		SPX_Checkout_Address_Snapshot::write_to_order( $order, $result['snapshot'], SPX_Checkout_Address_Snapshot::SOURCE_CLASSIC_CHECKOUT );
		self::remember( self::ids_from_snapshot( $result['snapshot'] ) );
	}

	public static function remember_posted_selection( string $posted ): void {
		parse_str( $posted, $data );
		$payload = self::payload_from_array( $data );
		if ( $payload['province_id'] || $payload['district_id'] || $payload['ward_id'] ) { self::remember( $payload ); }
	}

	public static function validate_payload( array $payload, bool $is_cod, SPX_Checkout_Address_Service $service ): array {
		foreach ( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) as $key ) {
			if ( empty( $payload[ $key ] ) ) { return array( 'valid' => false, 'error' => 'selection_required' ); }
		}
		return $service->validate_selection( (string) $payload['province_id'], (string) $payload['district_id'], (string) $payload['ward_id'], $is_cod, (string) $payload['dataset_version'] );
	}

	public static function get_prefill_for_blocks( SPX_Checkout_Address_Service $service ): array { return self::prefill( $service ); }
	public static function remember_snapshot( array $snapshot ): void { self::remember( self::ids_from_snapshot( $snapshot ) ); }
	public static function message_for_error( string $error ): string { return self::error_message( $error ); }

	private static function prefill( SPX_Checkout_Address_Service $service ): array {
		$blank = array( 'province_id' => '', 'district_id' => '', 'ward_id' => '', 'dataset_version' => $service->get_dataset_version() );
		$candidates = array();
		if ( function_exists( 'WC' ) && WC() && WC()->session ) { $candidates[] = (array) WC()->session->get( self::SESSION_KEY, array() ); }
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) { $candidates[] = (array) WC()->customer->get_meta( self::CUSTOMER_KEY, true ); }
		foreach ( $candidates as $candidate ) {
			$candidate += $blank;
			if ( $service->validate_selection( (string) $candidate['province_id'], (string) $candidate['district_id'], (string) $candidate['ward_id'], false, (string) $candidate['dataset_version'] )['valid'] ) { return $candidate; }
		}
		$matched = self::match_standard_shipping( $service );
		return $matched ?: $blank;
	}

	private static function match_standard_shipping( SPX_Checkout_Address_Service $service ): ?array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->customer ) { return null; }
		$customer = WC()->customer;
		$state = (string) $customer->get_shipping_state();
		if ( function_exists( 'WC' ) && WC()->countries ) { $states = WC()->countries->get_states( 'VN' ); if ( isset( $states[ $state ] ) ) { $state = $states[ $state ]; } }
		$repo = new SPX_Address_Repository();
		$p = $repo->find_province_by_name( $state );
		if ( 'matched' !== $p['status'] ) { return null; }
		$d = $repo->find_district_by_name( $p['code'], (string) $customer->get_shipping_city() );
		if ( 'matched' !== $d['status'] ) { return null; }
		$ward_name = (string) $customer->get_meta( 'shipping_ward', true );
		if ( '' === $ward_name ) { $ward_name = (string) $customer->get_meta( '_shipping_ward', true ); }
		$w = $repo->find_ward_by_name( $d['code'], $ward_name );
		if ( 'matched' !== $w['status'] ) { return null; }
		$result = $service->validate_selection( $p['code'], $d['code'], $w['code'], false, $service->get_dataset_version() );
		return $result['valid'] ? self::ids_from_snapshot( $result['snapshot'] ) : null;
	}

	private static function remember( array $selection ): void {
		$selection = array_intersect_key( $selection, array_flip( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) ) );
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$current=(array)WC()->session->get(self::SESSION_KEY,array());
			WC()->session->set( self::SESSION_KEY, $selection );
			if($current!==$selection){self::invalidate_shipping_package_cache();}
		}
		if ( function_exists( 'WC' ) && WC() && WC()->customer && WC()->customer->get_id() ) { WC()->customer->update_meta_data( self::CUSTOMER_KEY, $selection ); WC()->customer->save(); }
	}

	private static function invalidate_shipping_package_cache(): void {
		if(!function_exists('WC')||!WC()||!WC()->session)return;
		$packages=WC()->cart&&method_exists(WC()->cart,'get_shipping_packages')?(array)WC()->cart->get_shipping_packages():array(array());
		foreach(array_keys($packages) as $index){WC()->session->set('shipping_for_package_'.$index,false);}
	}

	private static function payload_from_array( array $data ): array {
		return array(
			'province_id' => isset( $data['spx_shipping_province_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_province_id'] ) ) : '',
			'district_id' => isset( $data['spx_shipping_district_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_district_id'] ) ) : '',
			'ward_id' => isset( $data['spx_shipping_ward_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_ward_id'] ) ) : '',
			'dataset_version' => isset( $data['spx_shipping_dataset_version'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_dataset_version'] ) ) : '',
		);
	}

	private static function ids_from_snapshot( array $snapshot ): array { return array( 'province_id' => $snapshot['province_id'], 'district_id' => $snapshot['district_id'], 'ward_id' => $snapshot['ward_id'], 'dataset_version' => $snapshot['dataset_version'] ); }
	private static function options( array $rows, bool $disable_inactive = false ): array { $out = array(); foreach ( $rows as $row ) { if ( ! $disable_inactive || ( $row['active'] && $row['delivery_supported'] ) ) { $out[ $row['id'] ] = $row['label']; } } return $out; }
	private static function error_message( string $error ): string {
		$messages = array(
			'selection_required' => __( 'Vui lòng chọn đầy đủ Tỉnh/Thành phố, Quận/Huyện và Phường/Xã giao hàng SPX.', 'spx-express-woocommerce' ),
			'dataset_version_mismatch' => __( 'Dữ liệu khu vực SPX đã được cập nhật. Vui lòng chọn lại địa chỉ giao hàng SPX.', 'spx-express-woocommerce' ),
			'hierarchy_invalid' => __( 'Địa chỉ giao hàng SPX không hợp lệ. Vui lòng chọn lại.', 'spx-express-woocommerce' ),
			'ward_unavailable' => __( 'Phường/Xã SPX đã chọn hiện không hoạt động.', 'spx-express-woocommerce' ),
			'delivery_unsupported' => __( 'SPX hiện không hỗ trợ giao hàng tại Phường/Xã đã chọn.', 'spx-express-woocommerce' ),
			'cod_unsupported' => __( 'SPX hiện không hỗ trợ COD tại Phường/Xã đã chọn.', 'spx-express-woocommerce' ),
		);
		return isset( $messages[ $error ] ) ? $messages[ $error ] : $messages['hierarchy_invalid'];
	}
}
