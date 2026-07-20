<?php
/**
 * PHASE 6G-G — Vietnam Checkout profile contract.
 *
 * Pins: (1) unified full-name field replaces first/last on the UI, (2)
 * country/city/state/postcode/company/address_2 hidden, (3) phone
 * required + email optional, (4) address_1 relabelled, (5) exact full-name
 * survives validation and HPOS-safe WC_Order persistence without guessing
 * Vietnamese family/given-name boundaries.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $t, $d = null ) { return $t; }
function apply_filters( $tag, $value ) { return $value; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function add_action() {}
function add_filter() {}

class WP_Error {
	private $errors = array();
	public function add( $code, $message ) { $this->errors[ $code ][] = $message; }
	public function get_error_codes() { return array_keys( $this->errors ); }
	public function get_error_messages() {
		$messages = array();
		foreach ( $this->errors as $group ) { $messages = array_merge( $messages, $group ); }
		return $messages;
	}
}

class WC_Order {
	public $billing_first_name = '';
	public $billing_last_name = '';
	public $shipping_first_name = '';
	public $shipping_last_name = '';
	public $billing_country = '';
	public $shipping_country = '';
	public $meta = array();
	public function set_billing_first_name( $value ) { $this->billing_first_name = $value; }
	public function set_billing_last_name( $value ) { $this->billing_last_name = $value; }
	public function set_shipping_first_name( $value ) { $this->shipping_first_name = $value; }
	public function set_shipping_last_name( $value ) { $this->shipping_last_name = $value; }
	public function get_billing_country() { return $this->billing_country; }
	public function get_shipping_country() { return $this->shipping_country; }
	public function set_billing_country( $value ) { $this->billing_country = $value; }
	public function set_shipping_country( $value ) { $this->shipping_country = $value; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
}

require_once dirname( __DIR__ ) . '/includes/checkout/class-spx-vn-checkout-profile.php';
$n = 0; function t( $c, $m ) { global $n; $n++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: $m\n"; }

// Simulate a stock WooCommerce billing fields array.
$stock = array(
	'billing' => array(
		'billing_first_name' => array( 'type' => 'text', 'label' => 'First name', 'required' => true, 'class' => array( 'form-row-first' ) ),
		'billing_last_name'  => array( 'type' => 'text', 'label' => 'Last name', 'required' => true, 'class' => array( 'form-row-last' ) ),
		'billing_company'    => array( 'type' => 'text', 'label' => 'Company', 'class' => array( 'form-row-wide' ) ),
		'billing_country'    => array( 'type' => 'country', 'label' => 'Country', 'required' => true ),
		'billing_address_1'  => array( 'type' => 'text', 'label' => 'Address', 'required' => true, 'class' => array( 'form-row-wide' ) ),
		'billing_address_2'  => array( 'type' => 'text', 'label' => 'Apartment', 'class' => array( 'form-row-wide' ) ),
		'billing_city'       => array( 'type' => 'text', 'label' => 'City', 'required' => true ),
		'billing_state'      => array( 'type' => 'state', 'label' => 'State', 'required' => true ),
		'billing_postcode'   => array( 'type' => 'text', 'label' => 'Postcode', 'required' => true ),
		'billing_phone'      => array( 'type' => 'tel', 'label' => 'Phone', 'required' => true ),
		'billing_email'      => array( 'type' => 'email', 'label' => 'Email', 'required' => true ),
	),
	'shipping' => array(
		'shipping_first_name' => array( 'type' => 'text', 'label' => 'First name', 'required' => true ),
		'shipping_last_name'  => array( 'type' => 'text', 'label' => 'Last name', 'required' => true ),
		'shipping_country'    => array( 'type' => 'country', 'label' => 'Country', 'required' => true ),
		'shipping_address_1'  => array( 'type' => 'text', 'label' => 'Address', 'required' => true ),
		'shipping_city'       => array( 'type' => 'text', 'label' => 'City', 'required' => true ),
		'shipping_state'      => array( 'type' => 'state', 'label' => 'State', 'required' => true ),
		'shipping_postcode'   => array( 'type' => 'text', 'label' => 'Postcode', 'required' => true ),
	),
	'order' => array(
		'order_comments' => array( 'type' => 'textarea', 'label' => 'Order notes', 'required' => false ),
	),
);

$out = SPX_VN_Checkout_Profile::modify_fields( $stock );

/* 1. Unified full-name inserted at top of billing. */
$billing_keys = array_keys( $out['billing'] );
t( 'billing_full_name' === $billing_keys[0], 'billing_full_name inserted first in billing group' );
t( false === $out['billing']['billing_full_name']['required'], 'full_name bypasses Woo required validation so only the custom validator owns its error' );
t( 'true' === $out['billing']['billing_full_name']['custom_attributes']['aria-required'], 'full_name remains announced as required to assistive technology' );
t( 10 === $out['billing']['billing_full_name']['priority'], 'full_name priority = 10' );
t( 'Họ tên' === $out['billing']['billing_full_name']['label'], 'full_name label in Vietnamese' );

/* 2. First/last name kept in form but hidden. */
t( in_array( 'spx-vn-hidden-field', $out['billing']['billing_first_name']['class'], true ), 'billing_first_name carries hidden-field class' );
t( false === $out['billing']['billing_first_name']['required'], 'billing_first_name not required (full_name replaces)' );
t( in_array( 'spx-vn-hidden-field', $out['billing']['billing_last_name']['class'], true ), 'billing_last_name carries hidden-field class' );
t( 'hidden' === $out['billing']['billing_first_name']['type'], 'billing_first_name is a real hidden input' );
t( 'hidden' === $out['billing']['billing_last_name']['type'], 'billing_last_name is a real hidden input' );
t( ! isset( $out['billing']['billing_first_name']['custom_attributes']['required'] ), 'hidden billing_first_name has no browser required attribute' );
t( ! isset( $out['billing']['billing_last_name']['custom_attributes']['aria-required'] ), 'hidden billing_last_name has no aria-required attribute' );

/* 3. Country/city/state/postcode/company/address_2 hidden. */
foreach ( array( 'billing_country', 'billing_city', 'billing_state', 'billing_postcode', 'billing_company', 'billing_address_2' ) as $k ) {
	t( in_array( 'spx-vn-hidden-field', $out['billing'][ $k ]['class'], true ), "$k hidden from UI" );
	t( false === $out['billing'][ $k ]['required'], "$k not required" );
}

/* 4. Phone required + Vietnamese label. */
t( true === $out['billing']['billing_phone']['required'], 'phone required' );
t( 'Số điện thoại' === $out['billing']['billing_phone']['label'], 'phone label in Vietnamese' );
t( 20 === $out['billing']['billing_phone']['priority'], 'phone priority = 20' );
t( array( 'form-row-first', 'spx-vn-field' ) === $out['billing']['billing_phone']['class'], 'phone has deterministic desktop half-row classes' );

/* 5. Email optional for guest. */
t( false === $out['billing']['billing_email']['required'], 'email optional for guest' );
t( 30 === $out['billing']['billing_email']['priority'], 'email priority = 30' );
t( array( 'form-row-last', 'spx-vn-field' ) === $out['billing']['billing_email']['class'], 'email has deterministic desktop half-row classes' );

/* 6. The unified location control participates in Woo field ordering. */
t( isset( $out['billing']['billing_spx_location'] ), 'billing_spx_location is registered as a WooCommerce billing field' );
t( 'spx_location' === $out['billing']['billing_spx_location']['type'], 'location uses the custom non-native control type' );
t( 40 === $out['billing']['billing_spx_location']['priority'], 'location priority = 40' );
t( array( 'form-row-wide', 'spx-vn-field' ) === $out['billing']['billing_spx_location']['class'], 'location is a deterministic full-width row' );

/* 7. Address_1 relabelled. */
t( 'Địa chỉ' === $out['billing']['billing_address_1']['label'], 'address_1 relabelled to "Địa chỉ"' );
t( 50 === $out['billing']['billing_address_1']['priority'], 'address priority = 50 so Khu vực can occupy priority 40' );
t( 70 === $out['order']['order_comments']['priority'], 'order notes priority = 70 after the delivery information group' );

/* 8. Shipping group also transformed. */
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_country']['class'], true ), 'shipping_country hidden' );
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_city']['class'], true ), 'shipping_city hidden' );
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_postcode']['class'], true ), 'shipping_postcode hidden' );

/* 9. Independent Woo address filters must keep billing/shipping contexts apart. */
t( method_exists( 'SPX_VN_Checkout_Profile', 'modify_shipping_address_fields' ), 'dedicated shipping-address callback exists' );
$shipping_filter_out = SPX_VN_Checkout_Profile::modify_shipping_address_fields( $stock['shipping'] );
t( ! isset( $shipping_filter_out['billing_full_name'] ), 'woocommerce_shipping_fields never injects a duplicate billing_full_name' );
t( 'hidden' === $shipping_filter_out['shipping_first_name']['type'], 'shipping first_name is hidden without being treated as billing' );

/* 10. default_country forces VN. */
t( 'VN' === SPX_VN_Checkout_Profile::default_country( 'US' ), 'default billing country forced to VN' );

/* 10. Exact Vietnamese full-name contract: never infer first/last boundaries. */
list( $first, $last ) = SPX_VN_Checkout_Profile::split_name( 'Nguyễn Văn A' );
t( 'Nguyễn Văn A' === $first && '' === $last, 'full name stays intact in WC first_name compatibility field' );
list( $first2, $last2 ) = SPX_VN_Checkout_Profile::split_name( '  Trần   Thị   Bích   Ngọc  ' );
t( 'Trần Thị Bích Ngọc' === $first2 && '' === $last2, 'full name normalization collapses whitespace without reordering' );
list( $first3, $last3 ) = SPX_VN_Checkout_Profile::split_name( 'Solo' );
t( 'Solo' === $first3 && '' === $last3, 'single-word name → first only' );
list( $first4, $last4 ) = SPX_VN_Checkout_Profile::split_name( '' );
t( '' === $first4 && '' === $last4, 'empty name → empty pair' );

/* 11. Only the custom validator owns full-name errors. */
$_POST = array( 'billing_full_name' => '  Nguyễn Văn A  ' );
$valid_errors = new WP_Error();
SPX_VN_Checkout_Profile::validate_full_name( array(), $valid_errors );
t( 0 === count( $valid_errors->get_error_codes() ), 'valid full name produces zero validation errors' );

$_POST = array( 'billing_full_name' => '   ' );
$empty_errors = new WP_Error();
SPX_VN_Checkout_Profile::validate_full_name( array(), $empty_errors );
t( 1 === count( $empty_errors->get_error_codes() ), 'empty full name produces exactly one validation error' );
t( array( 'Vui lòng nhập họ tên.' ) === $empty_errors->get_error_messages(), 'empty full name uses only the friendly Vietnamese error' );

$_POST = array( 'billing_full_name' => '<b>Nguyễn Văn A</b><script>alert(1)</script>' );
$unsafe_order = new WC_Order();
SPX_VN_Checkout_Profile::persist_full_name( $unsafe_order, array() );
t( 'Nguyễn Văn Aalert(1)' === $unsafe_order->meta[ SPX_VN_Checkout_Profile::META_FULL_NAME ], 'full name is sanitized at the POST boundary before order meta persistence' );

/* 12. WC_Order CRUD persistence keeps the exact normalized full name. */
$_POST = array( 'billing_full_name' => '  Trần   Thị   Bích Ngọc  ' );
$order = new WC_Order();
SPX_VN_Checkout_Profile::persist_full_name( $order, array() );
t( 'Trần Thị Bích Ngọc' === $order->billing_first_name, 'billing_first_name stores the exact normalized full name' );
t( '' === $order->billing_last_name, 'billing_last_name remains empty' );
t( 'Trần Thị Bích Ngọc' === $order->shipping_first_name, 'shipping compatibility name keeps exact order when no separate shipping address' );
t( '' === $order->shipping_last_name, 'shipping_last_name remains empty' );
t( 'Trần Thị Bích Ngọc' === $order->meta[ SPX_VN_Checkout_Profile::META_FULL_NAME ], '_spx_billing_full_name stores exact normalized full name' );

echo "All VN Checkout profile tests passed ($n assertions).\n";
