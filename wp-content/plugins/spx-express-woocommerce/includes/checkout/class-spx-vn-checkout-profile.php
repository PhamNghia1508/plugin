<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vietnam Checkout profile.
 *
 * Streamlines Classic Checkout for VN customers:
 *   - Unified "Họ tên" field (billing_full_name) instead of first/last.
 *   - Hides country/company/city/state/postcode/address_2 from the UI
 *     (fields remain in the form as hidden inputs so WooCommerce still
 *     processes them; values are autopopulated).
 *   - Phone required, email optional (unless creating an account).
 *   - Localised labels/placeholders that read naturally to Vietnamese
 *     shop owners.
 *
 * Contracts:
 *   - Opt-in via filter `spx_vn_checkout_profile_enabled` (default: true).
 *     Set the filter to false to restore standard WooCommerce Checkout.
 *   - Never removes fields from `$_POST` data or WC_Order — only hides
 *     from the UI. Order still persists with a full billing address.
 *   - Never touches gateways, Place Order, order review, or template.
 *   - Only fires when country resolves to VN (or when country hidden
 *     by this profile — because we then force VN as the country).
 */
final class SPX_VN_Checkout_Profile {

	const FULL_NAME_KEY = 'billing_full_name';
	const META_FULL_NAME = '_spx_billing_full_name';

	public static function init(): void {
		if ( ! self::enabled() ) { return; }
		add_filter( 'default_checkout_billing_country', array( __CLASS__, 'default_country' ), 20 );
		add_filter( 'default_checkout_shipping_country', array( __CLASS__, 'default_country' ), 20 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'modify_fields' ), PHP_INT_MAX );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'modify_billing_address_fields' ), PHP_INT_MAX );
		add_filter( 'woocommerce_shipping_fields', array( __CLASS__, 'modify_shipping_address_fields' ), PHP_INT_MAX );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'force_vn_locale' ), PHP_INT_MAX );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_full_name' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_full_name' ), 30, 2 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_wrapper_open' ), 5 );
		add_action( 'woocommerce_after_checkout_form', array( __CLASS__, 'render_wrapper_close' ), 100 );
	}

	/** Opt-in gate. Defaults to true; site can disable via filter. */
	public static function enabled(): bool {
		return (bool) apply_filters( 'spx_vn_checkout_profile_enabled', true );
	}

	public static function default_country( $country ) {
		return 'VN';
	}

	/** Force the VN locale to treat postcode as optional and phone as required
	 * (WooCommerce's built-in VN locale marks phone optional and postcode
	 * required, which is backwards for Vietnamese e-commerce). */
	public static function force_vn_locale( array $locale ): array {
		$vn = isset( $locale['VN'] ) && is_array( $locale['VN'] ) ? $locale['VN'] : array();
		$vn['postcode'] = array_merge( isset( $vn['postcode'] ) ? $vn['postcode'] : array(), array( 'required' => false, 'hidden' => true ) );
		$vn['state']    = array_merge( isset( $vn['state'] ) ? $vn['state'] : array(), array( 'required' => false, 'hidden' => true ) );
		$vn['city']     = array_merge( isset( $vn['city'] ) ? $vn['city'] : array(), array( 'required' => false, 'hidden' => true ) );
		$vn['phone']    = array_merge( isset( $vn['phone'] ) ? $vn['phone'] : array(), array( 'required' => true ) );
		$locale['VN']   = $vn;
		return $locale;
	}

	/**
	 * Add a namespaced wrapper class to the Checkout form so all VN-profile
	 * CSS can be scoped under `.spx-vn-checkout` without touching global
	 * WooCommerce selectors.
	 */
	public static function render_wrapper_open(): void {
		echo '<div class="spx-vn-checkout">';
	}

	public static function render_wrapper_close(): void {
		echo '</div>';
	}

	/**
	 * Main field transformation. Runs on `woocommerce_checkout_fields`
	 * (Classic Checkout only — Blocks uses its own schema).
	 */
	public static function modify_fields( array $fields ): array {
		if ( isset( $fields['billing'] ) ) { $fields['billing'] = self::transform_address_group( $fields['billing'], 'billing' ); }
		if ( isset( $fields['shipping'] ) ) { $fields['shipping'] = self::transform_address_group( $fields['shipping'], 'shipping' ); }
		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label']       = __( 'Ghi chú đơn hàng', 'spx-express-woocommerce' );
			$fields['order']['order_comments']['placeholder'] = __( 'Ghi chú thêm cho đơn hàng (tùy chọn)', 'spx-express-woocommerce' );
			$fields['order']['order_comments']['priority']    = 70;
		}
		return $fields;
	}

	/**
	 * Fires for get_billing_fields/get_shipping_fields where WooCommerce
	 * uses locale-based defaults; makes the same hiding apply consistently.
	 */
	public static function modify_address_fields( array $fields ): array {
		return self::transform_address_group( $fields, 'billing' );
	}

	public static function modify_billing_address_fields( array $fields ): array {
		return self::transform_address_group( $fields, 'billing' );
	}

	public static function modify_shipping_address_fields( array $fields ): array {
		return self::transform_address_group( $fields, 'shipping' );
	}

	private static function transform_address_group( array $group, string $context ): array {
		// Unified full-name field (only in billing group; shipping keeps its own).
		if ( 'billing' === $context ) {
			$group = array( self::FULL_NAME_KEY => array(
				'type'         => 'text',
				'label'        => __( 'Họ tên', 'spx-express-woocommerce' ),
				'placeholder'  => __( 'Nhập đầy đủ họ và tên', 'spx-express-woocommerce' ),
				// WooCommerce's built-in required validator would duplicate the
				// dedicated Vietnamese error added by validate_full_name().
				'required'     => false,
				'custom_attributes' => array( 'aria-required' => 'true' ),
				'label_class'  => array( 'spx-vn-required-label' ),
				'class'        => array( 'form-row-wide', 'spx-vn-field', 'spx-vn-field--fullname' ),
				'priority'     => 10,
				'autocomplete' => 'name',
			) ) + $group;
		}

		// Hide first/last name (still submitted; split from full_name on persist).
		foreach ( array( '_first_name', '_last_name' ) as $suffix ) {
			$key = $context . $suffix;
			if ( isset( $group[ $key ] ) ) { $group[ $key ] = self::hide_field( $group[ $key ] ); }
		}

		// Hide company/country/city/state/postcode/address_2/apartment from UI.
		foreach ( array( '_company', '_country', '_city', '_state', '_postcode', '_address_2' ) as $suffix ) {
			$key = $context . $suffix;
			if ( isset( $group[ $key ] ) ) { $group[ $key ] = self::hide_field( $group[ $key ] ); }
		}

		// Address_1: relabel + placeholder.
		$addr_key = $context . '_address_1';
		if ( isset( $group[ $addr_key ] ) ) {
			$group[ $addr_key ]['label']       = __( 'Địa chỉ', 'spx-express-woocommerce' );
			$group[ $addr_key ]['placeholder'] = __( 'Số nhà, tên đường, thôn/xóm…', 'spx-express-woocommerce' );
			$group[ $addr_key ]['priority']    = 50;
			$group[ $addr_key ]['class']       = array( 'form-row-wide', 'spx-vn-field' );
		}

		// Phone: required + Vietnamese label.
		$phone_key = $context . '_phone';
		if ( isset( $group[ $phone_key ] ) ) {
			$group[ $phone_key ]['label']       = __( 'Số điện thoại', 'spx-express-woocommerce' );
			$group[ $phone_key ]['placeholder'] = __( 'Ví dụ: 0912345678', 'spx-express-woocommerce' );
			$group[ $phone_key ]['required']    = true;
			$group[ $phone_key ]['priority']    = 20;
			$group[ $phone_key ]['class']       = array( 'form-row-first', 'spx-vn-field' );
		}

		// Email (billing only): optional for guests.
		if ( 'billing' === $context && isset( $group['billing_email'] ) ) {
			$group['billing_email']['label']       = __( 'Địa chỉ email', 'spx-express-woocommerce' );
			$group['billing_email']['placeholder'] = __( 'tùy chọn — dùng để nhận thông báo đơn hàng', 'spx-express-woocommerce' );
			$group['billing_email']['required']    = false;
			$group['billing_email']['priority']    = 30;
			$group['billing_email']['class']       = array( 'form-row-last', 'spx-vn-field' );
		}

		// Register the unified SPX location control as a real WooCommerce field
		// so priority ordering remains stable after every fragment refresh. Its
		// custom renderer owns the accessible non-native UI and canonical inputs.
		if ( 'billing' === $context ) {
			$group['billing_spx_location'] = array(
				'type'       => 'spx_location',
				'label'      => __( 'Khu vực', 'spx-express-woocommerce' ),
				'required'   => false,
				'class'      => array( 'form-row-wide', 'spx-vn-field' ),
				'priority'   => 40,
			);
		}

		return $group;
	}

	private static function hide_field( array $field ): array {
		$field['required'] = false;
		$field['type']     = 'hidden';
		$field['class']    = self::merge_classes( $field['class'] ?? array(), array( 'spx-vn-hidden-field' ) );
		unset( $field['autocomplete'], $field['validate'] );
		if ( isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ) {
			unset( $field['custom_attributes']['required'], $field['custom_attributes']['aria-required'] );
		}
		if ( isset( $field['label'] ) ) { $field['label'] = ''; }
		return $field;
	}

	private static function merge_classes( $current, array $add ): array {
		$current = is_array( $current ) ? $current : array( (string) $current );
		return array_values( array_unique( array_merge( $current, $add ) ) );
	}

	public static function validate_full_name( array $data, WP_Error $errors ): void {
		$full = self::posted_full_name();
		if ( '' === $full ) {
			$errors->add( 'billing_full_name_required', __( 'Vui lòng nhập họ tên.', 'spx-express-woocommerce' ) );
		}
	}

	public static function persist_full_name( WC_Order $order, array $data ): void {
		$full = self::posted_full_name();
		if ( '' === $full ) { return; }
		list( $first, $last ) = self::split_name( $full );
		$order->set_billing_first_name( $first );
		$order->set_billing_last_name( $last );
		$order->update_meta_data( self::META_FULL_NAME, $full );
		// Ensure VN country persisted on the order (some flows may drop it).
		if ( '' === (string) $order->get_billing_country() ) { $order->set_billing_country( 'VN' ); }
		// Mirror shipping name/country when the customer did not choose a
		// separate shipping address.
		$ship_to_different = ! empty( $_POST['ship_to_different_address'] );
		if ( ! $ship_to_different ) {
			$order->set_shipping_first_name( $first );
			$order->set_shipping_last_name( $last );
			if ( '' === (string) $order->get_shipping_country() ) { $order->set_shipping_country( 'VN' ); }
		}
	}

	/**
	 * Preserve a Vietnamese full name for WooCommerce compatibility.
	 *
	 * Vietnamese names cannot be safely divided into Western first/last-name
	 * fields. Store the normalized full string in first_name and keep last_name
	 * empty so displays, emails and labels retain the customer's exact order.
	 */
	public static function split_name( string $full ): array {
		$full  = trim( preg_replace( '/\s+/u', ' ', $full ) );
		if ( '' === $full ) { return array( '', '' ); }
		return array( $full, '' );
	}

	private static function posted_full_name(): string {
		if ( ! isset( $_POST[ self::FULL_NAME_KEY ] ) ) { return ''; }
		$full_name = sanitize_text_field( (string) wp_unslash( $_POST[ self::FULL_NAME_KEY ] ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $full_name ) );
	}
}
