<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single "Khu vực" location control for Classic Checkout.
 *
 * Renders ONE unified field that navigates Province → District → Ward
 * internally. Hidden inputs store canonical IDs. No separate three-select
 * fields visible to the customer.
 *
 * Contracts:
 *   - Only renders when SPX_Checkout_Mode_Detector::is_classic().
 *   - Injected via woocommerce_after_checkout_billing_form hook.
 *   - No template overrides. No global CSS selectors.
 *   - All events namespaced .spxCheckout.
 *   - Never resets payment method, customer fields, or quantities.
 */
final class SPX_Classic_Checkout_Address {
	const SESSION_KEY  = 'spx_shipping_address_selection';
	const CUSTOMER_KEY = '_spx_checkout_shipping_selection';

	public static function init(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_posted_selection' ) );
	}

	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) { return; }
		// Mode guard: only enqueue for Classic checkout.
		if ( class_exists( 'SPX_Checkout_Mode_Detector' ) && ! SPX_Checkout_Mode_Detector::is_classic() ) { return; }

		$style_version  = (string) filemtime( SPX_WC_PATH . 'assets/css/checkout-address.css' );
		$script_version = (string) filemtime( SPX_WC_PATH . 'assets/js/classic-checkout-address.js' );
		wp_enqueue_style( 'spx-checkout-address', SPX_WC_URL . 'assets/css/checkout-address.css', array(), $style_version );
		wp_enqueue_script( 'spx-classic-checkout-address', SPX_WC_URL . 'assets/js/classic-checkout-address.js', array( 'jquery' ), $script_version, true );
		wp_localize_script( 'spx-classic-checkout-address', 'spxCheckoutAddress', array(
			'restUrl'   => esc_url_raw( rest_url( SPX_Checkout_Address_REST_Controller::NAMESPACE ) ),
			'spxMethod' => 'spx_express',
			'labels'    => array(
				'choose'  => __( '— Chọn khu vực —', 'spx-express-woocommerce' ),
				'loading' => __( 'Đang tải…', 'spx-express-woocommerce' ),
				'error'   => __( 'Không thể tải địa chỉ SPX.', 'spx-express-woocommerce' ),
				'empty'   => __( 'Không tìm thấy', 'spx-express-woocommerce' ),
				'back'    => __( 'Quay lại', 'spx-express-woocommerce' ),
			),
		) );
	}

	/**
	 * Render a single "Khu vực" location control.
	 *
	 * Output:
	 *   - One display button showing the selected location string.
	 *   - A dropdown panel with search + cascading list.
	 *   - Hidden inputs for province_id, district_id, ward_id, dataset_version.
	 *   - A validation notice area.
	 *
	 * @param WC_Checkout|null $checkout
	 */
	public static function render( $checkout = null ): void {
		// Mode guard.
		if ( class_exists( 'SPX_Checkout_Mode_Detector' ) && ! SPX_Checkout_Mode_Detector::is_classic() ) { return; }

		$service  = new SPX_Checkout_Address_Service();
		$selected = self::prefill( $service );
		$required = SPX_Checkout_Eligibility::current_checkout_requires_selection();

		// Build display text from prefilled selection.
		$display_text = self::build_display_text( $selected, $service );
		$placeholder  = '' === $display_text;

		echo '<div id="spx-location-control" class="spx-checkout-field spx-location-control"'
			. ' data-spx-required="' . esc_attr( $required ? '1' : '0' ) . '"'
			. ' style="' . ( $required ? '' : 'display:none' ) . '">';

		// Label.
		echo '<label class="spx-location-control__label" for="spx-location-display">';
		echo esc_html__( 'Khu vực', 'spx-express-woocommerce' );
		echo ' <abbr title="' . esc_attr__( 'bắt buộc', 'spx-express-woocommerce' ) . '">*</abbr>';
		echo '</label>';

		// Display button.
		echo '<button type="button" id="spx-location-display" class="spx-location-control__display'
			. ( $placeholder ? ' spx-location-control__display--placeholder' : '' )
			. '" aria-haspopup="listbox" aria-expanded="false">';
		echo esc_html( $placeholder ? __( '— Chọn khu vực —', 'spx-express-woocommerce' ) : $display_text );
		echo '</button>';

		// Dropdown panel.
		echo '<div class="spx-location-control__panel" role="listbox">';
		echo '<input type="text" class="spx-location-control__search" placeholder="' . esc_attr__( 'Tìm kiếm…', 'spx-express-woocommerce' ) . '" autocomplete="off" />';
		echo '<div class="spx-location-control__breadcrumb" style="display:none"></div>';
		echo '<ul class="spx-location-control__list"></ul>';
		echo '</div>';

		// Hidden inputs with canonical IDs.
		echo '<input type="hidden" name="spx_shipping_province_id" value="' . esc_attr( $selected['province_id'] ) . '"'
			. ' data-label="' . esc_attr( $selected['province_name'] ?? '' ) . '" />';
		echo '<input type="hidden" name="spx_shipping_district_id" value="' . esc_attr( $selected['district_id'] ) . '"'
			. ' data-label="' . esc_attr( $selected['district_name'] ?? '' ) . '" />';
		echo '<input type="hidden" name="spx_shipping_ward_id" value="' . esc_attr( $selected['ward_id'] ) . '"'
			. ' data-label="' . esc_attr( $selected['ward_name'] ?? '' ) . '" />';
		echo '<input type="hidden" name="spx_shipping_dataset_version" value="' . esc_attr( $service->get_dataset_version() ) . '" />';

		// Validation feedback.
		echo '<p class="spx-checkout-notice" aria-live="polite"></p>';

		echo '</div>';
	}

	public static function validate_checkout( array $data, WP_Error $errors ): void {
		if ( ! SPX_Checkout_Eligibility::current_checkout_requires_selection() ) { return; }
		$payload = self::payload_from_array( $_POST );
		$is_cod  = isset( $data['payment_method'] ) && 'cod' === $data['payment_method'];
		$result  = self::validate_payload( $payload, $is_cod, new SPX_Checkout_Address_Service() );
		if ( ! $result['valid'] ) {
			$errors->add( 'spx_shipping_address_' . $result['error'], self::error_message( $result['error'] ) );
		}
	}

	public static function persist_order( WC_Order $order, array $data ): void {
		if ( ! SPX_Checkout_Eligibility::current_checkout_requires_selection() ) { return; }
		$result = self::validate_payload(
			self::payload_from_array( $_POST ),
			isset( $data['payment_method'] ) && 'cod' === $data['payment_method'],
			new SPX_Checkout_Address_Service()
		);
		if ( empty( $result['valid'] ) ) { return; }
		SPX_Checkout_Address_Snapshot::write_to_order( $order, $result['snapshot'], SPX_Checkout_Address_Snapshot::SOURCE_CLASSIC_CHECKOUT );
		self::remember( self::ids_from_snapshot( $result['snapshot'] ) );
	}

	public static function remember_posted_selection( string $posted ): void {
		parse_str( $posted, $data );
		$payload = self::payload_from_array( $data );
		if ( $payload['province_id'] || $payload['district_id'] || $payload['ward_id'] ) {
			self::remember( $payload );
		}
	}

	public static function validate_payload( array $payload, bool $is_cod, SPX_Checkout_Address_Service $service ): array {
		foreach ( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) as $key ) {
			if ( empty( $payload[ $key ] ) ) {
				return array( 'valid' => false, 'error' => 'selection_required' );
			}
		}
		return $service->validate_selection(
			(string) $payload['province_id'],
			(string) $payload['district_id'],
			(string) $payload['ward_id'],
			$is_cod,
			(string) $payload['dataset_version']
		);
	}

	public static function get_prefill_for_blocks( SPX_Checkout_Address_Service $service ): array {
		return self::prefill( $service );
	}

	public static function remember_snapshot( array $snapshot ): void {
		self::remember( self::ids_from_snapshot( $snapshot ) );
	}

	public static function message_for_error( string $error ): string {
		return self::error_message( $error );
	}

	// ── Private ───────────────────────────────────────────────────────

	private static function build_display_text( array $selected, SPX_Checkout_Address_Service $service ): string {
		if ( empty( $selected['province_id'] ) || empty( $selected['district_id'] ) || empty( $selected['ward_id'] ) ) {
			return '';
		}
		$province_name = $selected['province_name'] ?? '';
		$district_name = $selected['district_name'] ?? '';
		$ward_name     = $selected['ward_name'] ?? '';

		if ( '' === $province_name || '' === $district_name || '' === $ward_name ) {
			// Resolve from service.
			$result = $service->validate_selection( $selected['province_id'], $selected['district_id'], $selected['ward_id'], false, $selected['dataset_version'] ?? '' );
			if ( ! empty( $result['valid'] ) && isset( $result['snapshot'] ) ) {
				$province_name = $result['snapshot']['province_name'] ?? '';
				$district_name = $result['snapshot']['district_name'] ?? '';
				$ward_name     = $result['snapshot']['ward_name'] ?? '';
			}
		}

		if ( '' === $province_name || '' === $district_name || '' === $ward_name ) {
			return '';
		}

		return $province_name . ' - ' . $district_name . ' - ' . $ward_name;
	}

	private static function prefill( SPX_Checkout_Address_Service $service ): array {
		$blank = array( 'province_id' => '', 'district_id' => '', 'ward_id' => '', 'dataset_version' => $service->get_dataset_version(), 'province_name' => '', 'district_name' => '', 'ward_name' => '' );
		$candidates = array();
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$candidates[] = (array) WC()->session->get( self::SESSION_KEY, array() );
		}
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$candidates[] = (array) WC()->customer->get_meta( self::CUSTOMER_KEY, true );
		}
		foreach ( $candidates as $candidate ) {
			$candidate += $blank;
			$result = $service->validate_selection(
				(string) $candidate['province_id'],
				(string) $candidate['district_id'],
				(string) $candidate['ward_id'],
				false,
				(string) $candidate['dataset_version']
			);
			if ( ! empty( $result['valid'] ) ) {
				$out = $candidate;
				if ( isset( $result['snapshot'] ) ) {
					$out['province_name'] = $result['snapshot']['province_name'] ?? '';
					$out['district_name'] = $result['snapshot']['district_name'] ?? '';
					$out['ward_name']     = $result['snapshot']['ward_name'] ?? '';
				}
				return $out;
			}
		}
		$matched = self::match_standard_shipping( $service );
		return $matched ?: $blank;
	}

	private static function match_standard_shipping( SPX_Checkout_Address_Service $service ): ?array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->customer ) { return null; }
		$customer = WC()->customer;
		$state = (string) $customer->get_shipping_state();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( 'VN' );
			if ( isset( $states[ $state ] ) ) { $state = $states[ $state ]; }
		}
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
		if ( ! $result['valid'] ) { return null; }
		$out = self::ids_from_snapshot( $result['snapshot'] );
		$out['province_name'] = $result['snapshot']['province_name'] ?? '';
		$out['district_name'] = $result['snapshot']['district_name'] ?? '';
		$out['ward_name']     = $result['snapshot']['ward_name'] ?? '';
		return $out;
	}

	private static function remember( array $selection ): void {
		$selection = array_intersect_key( $selection, array_flip( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) ) );
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$current = (array) WC()->session->get( self::SESSION_KEY, array() );
			WC()->session->set( self::SESSION_KEY, $selection );
			if ( $current !== $selection ) {
				self::invalidate_shipping_package_cache();
			}
		}
		if ( function_exists( 'WC' ) && WC() && WC()->customer && WC()->customer->get_id() ) {
			WC()->customer->update_meta_data( self::CUSTOMER_KEY, $selection );
			WC()->customer->save();
		}
	}

	private static function invalidate_shipping_package_cache(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) { return; }
		$packages = WC()->cart && method_exists( WC()->cart, 'get_shipping_packages' )
			? (array) WC()->cart->get_shipping_packages()
			: array( array() );
		foreach ( array_keys( $packages ) as $index ) {
			WC()->session->set( 'shipping_for_package_' . $index, false );
		}
	}

	private static function payload_from_array( array $data ): array {
		return array(
			'province_id'     => isset( $data['spx_shipping_province_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_province_id'] ) ) : '',
			'district_id'     => isset( $data['spx_shipping_district_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_district_id'] ) ) : '',
			'ward_id'         => isset( $data['spx_shipping_ward_id'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_ward_id'] ) ) : '',
			'dataset_version' => isset( $data['spx_shipping_dataset_version'] ) ? sanitize_text_field( wp_unslash( $data['spx_shipping_dataset_version'] ) ) : '',
		);
	}

	private static function ids_from_snapshot( array $snapshot ): array {
		return array(
			'province_id'     => $snapshot['province_id'],
			'district_id'     => $snapshot['district_id'],
			'ward_id'         => $snapshot['ward_id'],
			'dataset_version' => $snapshot['dataset_version'],
		);
	}

	private static function error_message( string $error ): string {
		$messages = array(
			'selection_required'       => __( 'Vui lòng chọn đầy đủ khu vực giao hàng SPX.', 'spx-express-woocommerce' ),
			'dataset_version_mismatch' => __( 'Dữ liệu khu vực SPX đã được cập nhật. Vui lòng chọn lại khu vực giao hàng.', 'spx-express-woocommerce' ),
			'hierarchy_invalid'        => __( 'Khu vực giao hàng SPX không hợp lệ. Vui lòng chọn lại.', 'spx-express-woocommerce' ),
			'ward_unavailable'         => __( 'Phường/Xã SPX đã chọn hiện không hoạt động.', 'spx-express-woocommerce' ),
			'delivery_unsupported'     => __( 'SPX hiện không hỗ trợ giao hàng tại khu vực đã chọn.', 'spx-express-woocommerce' ),
			'cod_unsupported'          => __( 'SPX hiện không hỗ trợ COD tại khu vực đã chọn.', 'spx-express-woocommerce' ),
		);
		return isset( $messages[ $error ] ) ? $messages[ $error ] : $messages['hierarchy_invalid'];
	}
}
