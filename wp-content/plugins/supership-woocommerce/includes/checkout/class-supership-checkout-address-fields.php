<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Checkout Address Fields
 *
 * SuperShip's order-creation API requires exact, canonical
 * province/district/commune NAMES (not free text) - Tỉnh/Thành, Quận/Huyện,
 * and Phường/Xã (docs/integrations/supership-api-reference.md section 4.2;
 * re-verified live at docs.developers.supership.vn/guide/orders.html).
 * WooCommerce's default fields don't have a commune level at all, and the
 * province/city fields are free text for Vietnam (no state list registered).
 *
 * This class also optimizes the whole checkout for a Vietnamese, COD-first,
 * single-address shopping experience: no separate "ship to a different
 * address" step (billing = shipping, universal for this market), only the
 * fields actually needed for a SuperShip delivery, all in Vietnamese.
 *
 * Scope note: classic checkout only. The block-based Checkout (Gutenberg)
 * uses a separate extensibility API (Additional Checkout Fields) and is not
 * covered by this pass.
 *
 * @package SuperShip_WooCommerce
 * @version 0.3.0
 */
final class SuperShip_Checkout_Address_Fields {

	const META_KEY_COMMUNE = '_shipping_commune';
	const FIELD_KEY_COMMUNE = 'billing_commune';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_states', array( __CLASS__, 'register_vn_states' ) );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'override_checkout_fields' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'relabel_order_notes_field' ) );
		add_filter( 'woocommerce_admin_shipping_fields', array( __CLASS__, 'add_admin_commune_field' ) );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'customize_vn_locale' ) );
		add_filter( 'gettext', array( __CLASS__, 'translate_checkout_strings' ), 10, 3 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_commune_from_checkout' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
	}

	/**
	 * Removing the entire "Ship to a different address?" section (checkbox +
	 * duplicate shipping fields) is done via the `woocommerce_cart_needs_shipping_address`
	 * filter above, forced to false - this is WooCommerce's own native
	 * mechanism (see WC_Cart::needs_shipping_address(), and the identical
	 * effect the built-in "Force shipping to the customer billing address"
	 * setting has by writing `woocommerce_ship_to_destination = billing_only`
	 * - confirmed by reading templates/checkout/form-shipping.php and
	 * wc_ship_to_billing_address_only() directly in the installed WooCommerce
	 * source). Using the filter instead of writing that option directly
	 * avoids silently changing a setting the merchant would otherwise see
	 * reflected (or not) in WooCommerce > Settings > General.
	 *
	 * Vietnamese COD-first checkouts virtually never need a separate
	 * billing/shipping address - one address serves both. WooCommerce's own
	 * checkout processing already falls back to the billing address for the
	 * order's shipping fields whenever this section isn't rendered/submitted,
	 * so no further wiring is needed for shipping rate calculation or order
	 * creation to keep working.
	 */

	/**
	 * Register Vietnam's provinces as WooCommerce "states" for VN.
	 *
	 * This is the missing piece that actually makes the Tỉnh/Thành field
	 * appear at all: confirmed by reading WooCommerce core's own
	 * assets/js/frontend/country-select.js - the billing_state field is only
	 * rendered as a visible dropdown when `WC()->countries->get_states('VN')`
	 * returns a NON-EMPTY array. With no states registered (WooCommerce ships
	 * none for VN by default), the field is always hidden client-side
	 * regardless of the 'hidden' locale flag (see unhide_vn_state_field()
	 * below, which is still needed but not sufficient on its own).
	 *
	 * Uses the province NAME as both the array key and value (rather than a
	 * short ISO-style code) so the value WooCommerce stores in
	 * `_billing_state` (and, via the billing->shipping fallback, `_shipping_state`)
	 * is directly the canonical SuperShip name our services already expect -
	 * no separate code-to-name lookup needed anywhere else in the plugin.
	 *
	 * @param array $states Existing country => states map.
	 * @return array
	 */
	public static function register_vn_states( array $states ): array {
		$provinces = self::get_province_options();

		if ( ! empty( $provinces ) ) {
			$states['VN'] = $provinces;
		}

		return $states;
	}

	/**
	 * WooCommerce core marks the "state" field as hidden+not-required for
	 * Vietnam by default (no ISO state list registered for VN) - confirmed
	 * live via `wp eval` against WC_Countries::get_country_locale()['VN'].
	 * Without this override, our Tỉnh/Thành dropdown would never actually
	 * render on checkout regardless of its field definition in
	 * override_checkout_fields(), since this per-country locale filter is
	 * applied after woocommerce_billing_fields.
	 *
	 * This ALSO carries 'label'/'placeholder' for state/city/address_1/phone,
	 * which is not just belt-and-suspenders: WooCommerce's own
	 * assets/js/frontend/address-i18n.js re-applies label/placeholder/priority
	 * to those specific fields client-side, on every page load, straight from
	 * this SAME locale array (via wc_address_i18n_params.locale) - confirmed
	 * live by inspecting wc_address_i18n_params.locale_fields, which lists
	 * exactly: address_1, address_2, city, country, phone, postcode, state.
	 * Labels set only via override_checkout_fields() (PHP field array) get
	 * silently overwritten back to WooCommerce's generic English default for
	 * any of those keys the instant the page's JS runs - confirmed by
	 * comparing WC_Countries::get_address_fields() (correct, Vietnamese)
	 * against the live DOM after JS ran (reverted to English) in the running
	 * preview. So every field in that JS list that we relabel in PHP must
	 * also be relabeled here, or the JS overwrite wins.
	 *
	 * @param array $locale Country locale overrides.
	 * @return array
	 */
	public static function customize_vn_locale( array $locale ): array {
		if ( ! isset( $locale['VN'] ) ) {
			$locale['VN'] = array();
		}

		$locale['VN']['state'] = array(
			'label'    => __( 'Tỉnh/Thành phố', 'supership-woocommerce' ),
			'required' => true,
			'hidden'   => false,
			// See class docblock note above: priority here drives the JS re-sort,
			// not just the initial PHP render.
			'priority' => 50,
		);

		$locale['VN']['city'] = array(
			'label'    => __( 'Quận/Huyện', 'supership-woocommerce' ),
			'required' => true,
			'priority' => 60,
		);

		$locale['VN']['address_1'] = array(
			'label'       => __( 'Địa chỉ cụ thể', 'supership-woocommerce' ),
			'placeholder' => __( 'Số nhà, tên đường, tòa nhà...', 'supership-woocommerce' ),
			'priority'    => 40,
		);

		$locale['VN']['phone'] = array(
			'label'    => __( 'Số điện thoại', 'supership-woocommerce' ),
			// Required: SuperShip's shipper calls this number to coordinate
			// pickup/delivery - must match override_checkout_fields()'s PHP-side
			// 'required' flag, since address-i18n.js re-applies this from the
			// locale array client-side and would otherwise win with the
			// (falsy) default and silently make phone optional again.
			'required' => true,
		);

		return $locale;
	}

	/**
	 * Streamline the billing/checkout address fields for a Vietnamese,
	 * COD-first delivery flow: relabel to Vietnamese, drop fields that add
	 * friction without adding value for a domestic parcel delivery, and wire
	 * the Tỉnh/Thành -> Quận/Huyện -> Phường/Xã cascade.
	 *
	 * Fields kept: full name (single field), phone, specific address,
	 * province, district, commune. Email stays (WooCommerce requires it for
	 * order confirmation / account access - not a real optional field
	 * despite not being on the requested list).
	 *
	 * Fields dropped from view: last name (folded into a single name field),
	 * company, address line 2, postcode, country selector (this integration
	 * is Vietnam-only - the country field is kept in the DOM and functional,
	 * just hidden, because country-select.js/address-i18n.js depend on a
	 * live, unhidden-in-markup element to drive the state/city cascade).
	 *
	 * @param array $fields Existing billing fields.
	 * @return array
	 */
	public static function override_checkout_fields( array $fields ): array {
		if ( ! isset( $fields['billing_state'] ) || ! isset( $fields['billing_city'] ) ) {
			return $fields;
		}

		$provinces = self::get_province_options();

		// Single combined name field - Vietnamese checkouts expect one
		// "Họ và tên" field, not separate first/last name.
		if ( isset( $fields['billing_first_name'] ) ) {
			$fields['billing_first_name']['label']       = __( 'Họ và tên người nhận', 'supership-woocommerce' );
			$fields['billing_first_name']['placeholder'] = __( 'Nguyễn Văn A', 'supership-woocommerce' );
			$fields['billing_first_name']['class']       = array( 'form-row-wide' );
			$fields['billing_first_name']['priority']    = 10;
		}
		if ( isset( $fields['billing_last_name'] ) ) {
			$fields['billing_last_name']['required'] = false;
			$fields['billing_last_name']['class']    = array( 'supership-field-hidden' );
		}

		// Not needed for a consumer parcel delivery.
		if ( isset( $fields['billing_company'] ) ) {
			$fields['billing_company']['required'] = false;
			$fields['billing_company']['class']     = array( 'supership-field-hidden' );
		}
		if ( isset( $fields['billing_address_2'] ) ) {
			$fields['billing_address_2']['required'] = false;
			$fields['billing_address_2']['class']     = array( 'supership-field-hidden' );
		}
		if ( isset( $fields['billing_postcode'] ) ) {
			$fields['billing_postcode']['required'] = false;
			$fields['billing_postcode']['class']    = array( 'supership-field-hidden' );
		}

		// Country: SuperShip only ships within Vietnam, so there is nothing
		// for the customer to actually choose. Kept in the DOM (not removed)
		// because WooCommerce's own state-select JS reads/targets this field
		// to decide which state list to show - hidden visually only.
		if ( isset( $fields['billing_country'] ) ) {
			$existing_class                       = isset( $fields['billing_country']['class'] ) ? (array) $fields['billing_country']['class'] : array();
			$fields['billing_country']['class']   = array_merge( $existing_class, array( 'supership-field-hidden' ) );
		}

		if ( isset( $fields['billing_address_1'] ) ) {
			$fields['billing_address_1']['label']       = __( 'Địa chỉ cụ thể', 'supership-woocommerce' );
			$fields['billing_address_1']['placeholder'] = __( 'Số nhà, tên đường, tòa nhà...', 'supership-woocommerce' );
			$fields['billing_address_1']['priority']    = 40;
		}

		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['label']       = __( 'Số điện thoại', 'supership-woocommerce' );
			$fields['billing_phone']['placeholder'] = __( '0901 234 567', 'supership-woocommerce' );
			// Required: SuperShip's shipper calls this number to coordinate pickup/delivery -
			// an order with no phone number is not practically deliverable.
			$fields['billing_phone']['required']    = true;
		}

		if ( isset( $fields['billing_email'] ) ) {
			$fields['billing_email']['label'] = __( 'Email', 'supership-woocommerce' );
		}

		$fields['billing_state'] = array_merge(
			$fields['billing_state'],
			array(
				'label'    => __( 'Tỉnh/Thành phố', 'supership-woocommerce' ),
				'type'     => ! empty( $provinces ) ? 'select' : 'text',
				'options'  => array( '' => __( '— Chọn Tỉnh/Thành —', 'supership-woocommerce' ) ) + $provinces,
				'priority' => 50,
			)
		);

		$fields['billing_city'] = array_merge(
			$fields['billing_city'],
			array(
				'label'             => __( 'Quận/Huyện', 'supership-woocommerce' ),
				'type'              => 'select',
				'options'           => array( '' => __( '— Chọn Tỉnh/Thành trước —', 'supership-woocommerce' ) ),
				'custom_attributes' => array( 'data-supership-saved' => self::get_saved_value( 'billing_city' ) ),
				'priority'          => 60,
			)
		);

		$fields[ self::FIELD_KEY_COMMUNE ] = array(
			'label'             => __( 'Phường/Xã', 'supership-woocommerce' ),
			'required'          => true,
			'type'              => 'select',
			'options'           => array( '' => __( '— Chọn Quận/Huyện trước —', 'supership-woocommerce' ) ),
			'custom_attributes' => array( 'data-supership-saved' => self::get_saved_value( self::FIELD_KEY_COMMUNE ) ),
			'class'             => array( 'form-row-wide' ),
			'priority'          => 65,
		);

		return $fields;
	}

	/**
	 * Relabel the order notes field in Vietnamese. Kept as-is otherwise
	 * (optional, free text).
	 *
	 * @param array $fields Full checkout fields array (billing/shipping/order/account).
	 * @return array
	 */
	public static function relabel_order_notes_field( array $fields ): array {
		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label']       = __( 'Ghi chú đơn hàng', 'supership-woocommerce' );
			$fields['order']['order_comments']['placeholder'] = __( 'Ví dụ: giao giờ hành chính, gọi trước khi giao...', 'supership-woocommerce' );
		}

		return $fields;
	}

	/**
	 * Translate a curated list of WooCommerce's own static checkout strings
	 * to Vietnamese, scoped strictly to the checkout page. This does not
	 * depend on the site's WPLANG being set to Vietnamese (many stores keep
	 * their wp-admin in English while wanting a Vietnamese storefront), and
	 * does not affect any other page.
	 *
	 * @param string $translated Translated text.
	 * @param string $original   Original (English) text.
	 * @param string $domain     Text domain.
	 * @return string
	 */
	public static function translate_checkout_strings( string $translated, string $original, string $domain ): string {
		if ( 'woocommerce' !== $domain || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $translated;
		}

		static $map = null;

		if ( null === $map ) {
			$map = array(
				'Billing details'                            => __( 'Thông tin giao hàng', 'supership-woocommerce' ),
				'Billing &amp; Shipping'                      => __( 'Thông tin giao hàng', 'supership-woocommerce' ),
				'Your order'                                  => __( 'Đơn hàng của bạn', 'supership-woocommerce' ),
				'Place order'                                 => __( 'Đặt hàng', 'supership-woocommerce' ),
				'Have a coupon?'                               => __( 'Bạn có mã giảm giá?', 'supership-woocommerce' ),
				'Click here to enter your code'               => __( 'Bấm vào đây để nhập mã', 'supership-woocommerce' ),
				'Product'                                     => __( 'Sản phẩm', 'supership-woocommerce' ),
				'Subtotal'                                     => __( 'Tạm tính', 'supership-woocommerce' ),
				'Total'                                       => __( 'Tổng cộng', 'supership-woocommerce' ),
				'Additional information'                      => __( 'Thông tin thêm', 'supership-woocommerce' ),
				'Coupon code'                                  => __( 'Mã giảm giá', 'supership-woocommerce' ),
				'Apply coupon'                                 => __( 'Áp dụng', 'supership-woocommerce' ),
				'(optional)'                                   => __( '(không bắt buộc)', 'supership-woocommerce' ),
				'optional'                                     => __( 'không bắt buộc', 'supership-woocommerce' ),
				'Shipping'                                     => __( 'Vận chuyển', 'supership-woocommerce' ),
				'Shipment'                                     => __( 'Vận chuyển', 'supership-woocommerce' ),
				'No shipping options were found'              => __( 'Không tìm thấy phương thức vận chuyển phù hợp', 'supership-woocommerce' ),
				'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.' => __( 'Hiện chưa có phương thức thanh toán nào khả dụng. Vui lòng liên hệ với chúng tôi để được hỗ trợ.', 'supership-woocommerce' ),
			);
		}

		return $map[ $original ] ?? $translated;
	}

	/**
	 * Get the province options list from SuperShip's cached Areas API.
	 *
	 * Returns an empty array (falls back to a plain text field) if
	 * credentials aren't configured yet or the API call fails - this keeps
	 * checkout usable even before the merchant finishes setup.
	 *
	 * @return array {name => name}
	 */
	private static function get_province_options(): array {
		if ( ! class_exists( 'SuperShip_Address_Repository' ) ) {
			return array();
		}

		$repo    = new SuperShip_Address_Repository();
		$options = array();

		foreach ( $repo->get_provinces() as $province ) {
			$options[ $province['name'] ] = $province['name'];
		}

		return $options;
	}

	/**
	 * Get a previously posted/session checkout field value, for restoring
	 * the district/commune dropdown selection after WooCommerce re-renders
	 * the checkout form (e.g. after an "updated_checkout" AJAX refresh).
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function get_saved_value( string $key ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return '';
		}

		$value = WC()->checkout()->get_value( $key );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Add commune field to the admin order edit "Shipping" address box.
	 * Kept as a plain text field here (not a dropdown) - admins
	 * occasionally need to manually correct an address, and this is a
	 * lower-stakes internal tool compared to customer-facing checkout.
	 *
	 * WooCommerce automatically reads/writes this as post meta
	 * `_shipping_commune` because the array key is `commune`.
	 *
	 * @param array $fields Existing admin shipping fields.
	 * @return array
	 */
	public static function add_admin_commune_field( array $fields ): array {
		$fields['commune'] = array(
			'label' => __( 'Phường/Xã', 'supership-woocommerce' ),
			'show'  => true,
		);

		return $fields;
	}

	/**
	 * Save the posted commune (billing_commune) to the order's canonical
	 * `_shipping_commune` meta - the key SuperShip_Order_Actions and other
	 * services already read. WooCommerce's own field-saving loop only knows
	 * about native fields (state/city/address_1/etc.), not this custom one,
	 * so it needs an explicit save here.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_commune_from_checkout( int $order_id ): void {
		if ( ! isset( $_POST[ self::FIELD_KEY_COMMUNE ] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$commune = sanitize_text_field( wp_unslash( $_POST[ self::FIELD_KEY_COMMUNE ] ) );

		$order->update_meta_data( self::META_KEY_COMMUNE, $commune );
		$order->update_meta_data( '_billing_commune', $commune );
		$order->save();
	}

	/**
	 * Enqueue the cascading dropdown script + hide-unnecessary-fields CSS on
	 * pages that need it.
	 */
	public static function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'supership-checkout-address',
			SUPERSHIP_WC_URL . 'assets/js/checkout-address.js',
			array( 'jquery' ),
			SUPERSHIP_WC_VERSION,
			true
		);

		wp_localize_script(
			'supership-checkout-address',
			'supershipCheckoutAddress',
			array(
				'restUrl' => rest_url( SuperShip_Checkout_Areas_REST_Controller::REST_NAMESPACE ),
				'labels'  => array(
					'chooseDistrict' => __( '— Chọn Quận/Huyện —', 'supership-woocommerce' ),
					'chooseCommune'  => __( '— Chọn Phường/Xã —', 'supership-woocommerce' ),
					'loading'        => __( '— Đang tải... —', 'supership-woocommerce' ),
					'error'          => __( '— Không tải được, thử lại —', 'supership-woocommerce' ),
				),
			)
		);

		wp_register_style( 'supership-checkout-inline', false, array(), SUPERSHIP_WC_VERSION );
		wp_enqueue_style( 'supership-checkout-inline' );
		wp_add_inline_style(
			'supership-checkout-inline',
			'.supership-field-hidden { display: none !important; }'
		);
	}
}
