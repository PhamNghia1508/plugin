<?php
declare( strict_types=1 );

/**
 * Integration: Create (SPX_Order_Mapper) now emits canonical dimensions, and the
 * Create parcel geometry equals the checkout Rate parcel geometry for identical
 * product input (single deterministic formula). No network, no SPX API.
 * Run: php tests/test-spx-create-parcel-integration.php
 */

define( 'ABSPATH', __DIR__ );

function __( $t, $d = '' ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_textarea_field( $t ) { return sanitize_text_field( $t ); }
function get_bloginfo( $k = '' ) { return 'Test Shop'; }
function get_option( $k, $d = false ) { return 'woocommerce_dimension_unit' === $k ? 'cm' : $d; }
function wc_get_weight( $value, $to, $from = '' ) {
	$from = $from ?: 'kg';
	$g = array( 'kg' => 1000, 'g' => 1, 'lbs' => 453.59237, 'oz' => 28.349523125 );
	return (float) $value * $g[ $from ] / $g[ $to ];
}
$GLOBALS['spx_products'] = array();
function wc_get_product( $id ) { return $GLOBALS['spx_products'][ $id ] ?? null; }
function WC() { return new class() { public $countries; public function __construct() { $this->countries = new class() { public function get_base_address() { return 'Shop'; } public function get_base_state() { return 'SG'; } }; } }; }

class WC_Order {}

class SPX_Test_Product {
	public $id; private $weight; private $dims; private $virtual; private $variation; private $parent;
	public function __construct( $id, $weight, $dims = array(), $virtual = false, $variation = false, $parent = 0 ) {
		$this->id = $id; $this->weight = $weight; $this->dims = $dims; $this->virtual = $virtual; $this->variation = $variation; $this->parent = $parent;
	}
	public function get_id() { return $this->id; }
	public function get_weight() { return $this->weight; }
	public function get_length() { return $this->dims[0] ?? ''; }
	public function get_width() { return $this->dims[1] ?? ''; }
	public function get_height() { return $this->dims[2] ?? ''; }
	public function is_virtual() { return $this->virtual; }
	public function is_downloadable() { return false; }
	public function is_type( $t ) { return $this->variation && 'variation' === $t; }
	public function get_parent_id() { return $this->parent; }
	public function get_name() { return 'Item ' . $this->id; }
}
class SPX_Test_Item {
	private $product; private $qty;
	public function __construct( $product, $qty ) { $this->product = $product; $this->qty = $qty; }
	public function get_product() { return $this->product; }
	public function get_quantity() { return $this->qty; }
	public function get_name() { return $this->product ? $this->product->get_name() : 'Item'; }
}
class SPX_Test_Order extends WC_Order {
	private $items;
	public function __construct( array $items ) { $this->items = $items; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_id() { return 501; }
	public function get_order_number() { return '501'; }
	public function get_item_total( $item, $a = false, $b = false ) { return 100000.0; }
	public function get_shipping_first_name() { return 'Nguyen'; }
	public function get_shipping_last_name() { return 'Van A'; }
	public function get_billing_first_name() { return 'Nguyen'; }
	public function get_billing_last_name() { return 'Van A'; }
	public function get_shipping_address_1() { return '1 Test St'; }
	public function get_shipping_address_2() { return ''; }
	public function get_billing_address_1() { return '1 Test St'; }
	public function get_billing_address_2() { return ''; }
	public function get_billing_phone() { return '0980000000'; }
	public function get_shipping_state() { return 'SG'; }
	public function get_shipping_city() { return 'Q1'; }
	public function get_billing_state() { return 'SG'; }
	public function get_billing_city() { return 'Q1'; }
	public function get_payment_method() { return 'bacs'; }
	public function get_status() { return 'processing'; }
	public function is_paid() { return true; }
	public function needs_payment() { return false; }
	public function get_total() { return 200000.0; }
	public function get_customer_note() { return ''; }
}

require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-validation-result.php';
require_once dirname( __DIR__ ) . '/includes/parcel/class-spx-parcel-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-payment-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-order-mapper.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-request-builder.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

// Fixture: physical product 0.6 kg, dims 20×10×5 cm, qty 2 + one virtual line.
$product = new SPX_Test_Product( 10, '0.6', array( 20, 10, 5 ) );
$virtual = new SPX_Test_Product( 11, '2', array(), true );
$order   = new SPX_Test_Order( array( new SPX_Test_Item( $product, 2 ), new SPX_Test_Item( $virtual, 4 ) ) );

$payload = SPX_Order_Mapper::build( $order, array() );

// Create now emits dimensions (previously absent → wire mapper sent none).
check( isset( $payload['length_cm'], $payload['width_cm'], $payload['height_cm'] ), 'create emits dimensions' );
check( 20.0 === (float) $payload['length_cm'], 'create length = max = 20' );
check( 10.0 === (float) $payload['width_cm'], 'create width = max = 10' );
check( 10.0 === (float) $payload['height_cm'], 'create height = Σ(h·qty) = 5·2 = 10' );
check( isset( $payload['parcel']['_spx_parcel_policy_version'] ), 'parcel snapshot present' );
check( 'product' === $payload['parcel_source'], 'parcel source = product' );

// Rate path from the equivalent checkout package (virtual excluded there too).
$validator = function ( $sel, $cod ) { return array( 'valid' => true, 'snapshot' => array_merge( $sel, array( 'province_name' => 'HCM', 'district_name' => 'Q1', 'ward_name' => 'DK' ) ) ); };
$builder   = new SPX_Checkout_Rate_Request_Builder( $validator );
$sender    = array( 'sender_name' => 'S', 'sender_phone' => '0900000000', 'sender_detail_address' => 'A', 'sender_province_code' => 'p', 'sender_district_code' => 'd', 'sender_ward_code' => 'w', 'sender_province_name' => 'HCM', 'sender_district_name' => 'Q1', 'sender_ward_name' => 'BN', 'service_type' => 1, 'collect_type' => 2 );
$selection = array( 'province_id' => 'p2', 'district_id' => 'd2', 'ward_id' => 'w2', 'dataset_version' => 'v1' );
$package   = array( 'contents' => array( array( 'data' => $product, 'quantity' => 2, 'line_total' => 200000 ), array( 'data' => $virtual, 'quantity' => 4, 'line_total' => 1 ) ), 'contents_cost' => 200000, 'destination' => array( 'first_name' => 'Nguyen', 'last_name' => 'Van A', 'phone' => '0980000000', 'address' => '1 Test St' ) );
$rate = $builder->build( $package, $selection, $sender, array( 'is_cod' => false ) );

check( ! empty( $rate['success'] ), 'rate build succeeds' );
// Core consistency guarantee: Rate and Create produce identical geometry.
check( (float) $payload['length_cm'] === (float) $rate['shipment']['length_cm'], 'rate/create length identical' );
check( (float) $payload['width_cm'] === (float) $rate['shipment']['width_cm'], 'rate/create width identical' );
check( (float) $payload['height_cm'] === (float) $rate['shipment']['height_cm'], 'rate/create height identical' );
check( 1200 === (int) $rate['shipment']['weight_grams'] && 1200 === (int) $payload['weight_grams'], 'rate/create weight identical (1200 g, virtual excluded)' );

if ( 0 === $failures ) { echo "OK test-spx-create-parcel-integration\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
