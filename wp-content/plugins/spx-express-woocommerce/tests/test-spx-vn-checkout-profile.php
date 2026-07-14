<?php
/**
 * PHASE 6G-G — Vietnam Checkout profile contract.
 *
 * Pins: (1) unified full-name field replaces first/last on the UI, (2)
 * country/city/state/postcode/company/address_2 hidden, (3) phone
 * required + email optional, (4) address_1 relabelled, (5) full-name
 * splits into WC-compatible first/last on persist.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $t, $d = null ) { return $t; }
function apply_filters( $tag, $value ) { return $value; }
function wp_unslash( $v ) { return $v; }
function add_action() {}
function add_filter() {}

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
);

$out = SPX_VN_Checkout_Profile::modify_fields( $stock );

/* 1. Unified full-name inserted at top of billing. */
$billing_keys = array_keys( $out['billing'] );
t( 'billing_full_name' === $billing_keys[0], 'billing_full_name inserted first in billing group' );
t( true === $out['billing']['billing_full_name']['required'], 'full_name is required' );
t( 5 === $out['billing']['billing_full_name']['priority'], 'full_name priority = 5 (top)' );
t( 'Họ tên' === $out['billing']['billing_full_name']['label'], 'full_name label in Vietnamese' );

/* 2. First/last name kept in form but hidden. */
t( in_array( 'spx-vn-hidden-field', $out['billing']['billing_first_name']['class'], true ), 'billing_first_name carries hidden-field class' );
t( false === $out['billing']['billing_first_name']['required'], 'billing_first_name not required (full_name replaces)' );
t( in_array( 'spx-vn-hidden-field', $out['billing']['billing_last_name']['class'], true ), 'billing_last_name carries hidden-field class' );

/* 3. Country/city/state/postcode/company/address_2 hidden. */
foreach ( array( 'billing_country', 'billing_city', 'billing_state', 'billing_postcode', 'billing_company', 'billing_address_2' ) as $k ) {
	t( in_array( 'spx-vn-hidden-field', $out['billing'][ $k ]['class'], true ), "$k hidden from UI" );
	t( false === $out['billing'][ $k ]['required'], "$k not required" );
}

/* 4. Phone required + Vietnamese label. */
t( true === $out['billing']['billing_phone']['required'], 'phone required' );
t( 'Số điện thoại' === $out['billing']['billing_phone']['label'], 'phone label in Vietnamese' );

/* 5. Email optional for guest. */
t( false === $out['billing']['billing_email']['required'], 'email optional for guest' );

/* 6. Address_1 relabelled. */
t( 'Địa chỉ' === $out['billing']['billing_address_1']['label'], 'address_1 relabelled to "Địa chỉ"' );

/* 7. Shipping group also transformed. */
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_country']['class'], true ), 'shipping_country hidden' );
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_city']['class'], true ), 'shipping_city hidden' );
t( in_array( 'spx-vn-hidden-field', $out['shipping']['shipping_postcode']['class'], true ), 'shipping_postcode hidden' );

/* 8. default_country forces VN. */
t( 'VN' === SPX_VN_Checkout_Profile::default_country( 'US' ), 'default billing country forced to VN' );

/* 9. Name splitting: Vietnamese "Nguyễn Văn A" → first="Nguyễn Văn" last="A". */
list( $first, $last ) = SPX_VN_Checkout_Profile::split_name( 'Nguyễn Văn A' );
t( 'Nguyễn Văn' === $first && 'A' === $last, 'name split: family name first, given name last' );
list( $first2, $last2 ) = SPX_VN_Checkout_Profile::split_name( '  Trần   Thị   Bích   Ngọc  ' );
t( 'Trần Thị Bích' === $first2 && 'Ngọc' === $last2, 'name split collapses whitespace' );
list( $first3, $last3 ) = SPX_VN_Checkout_Profile::split_name( 'Solo' );
t( 'Solo' === $first3 && '' === $last3, 'single-word name → first only' );
list( $first4, $last4 ) = SPX_VN_Checkout_Profile::split_name( '' );
t( '' === $first4 && '' === $last4, 'empty name → empty pair' );

echo "All VN Checkout profile tests passed ($n assertions).\n";
