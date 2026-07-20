<?php
/** Network-free checkout snapshot and resolver precedence tests. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }

class WC_Order {
	public $meta = array();
	public $saves = 0;
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() { $this->saves++; }
	public function get_shipping_state() { return ''; }
	public function get_shipping_city() { return ''; }
	public function get_billing_state() { return ''; }
	public function get_billing_city() { return ''; }
}

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'address/class-spx-address-normalizer.php';
require_once $base . 'address/class-spx-address-import-service.php';
require_once $base . 'address/class-spx-address-repository.php';
require_once $base . 'class-spx-order-address.php';
require_once $base . 'checkout/class-spx-checkout-address-service.php';
require_once $base . 'checkout/class-spx-checkout-address-snapshot.php';
require_once $base . 'address/class-spx-wc-address-resolver.php';

$GLOBALS['ss_n'] = 0;
function st( $condition, $message ) { $GLOBALS['ss_n']++; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: {$message}\n"; }

$rows = array( array( 'id' => '101', 'ward' => 'Ward', 'district' => 'District', 'province' => 'Province', 'delivery' => 'Y', 'pickup' => 'Y', 'cod' => 'Y', 'status' => 'Available' ) );
$built = ( new SPX_Address_Import_Service() )->validate_and_build( $rows );
$data = $built['dataset']; $data['meta'] = array( 'version' => 'v1' );
$repo = SPX_Address_Repository::from_array( $data );
$service = new SPX_Checkout_Address_Service( $repo );
$p = $service->get_provinces()[0]['id'];
$d = $service->get_districts( $p )[0]['id'];
$validated = $service->validate_selection( $p, $d, '101', false, 'v1' );

$order = new WC_Order();
SPX_Checkout_Address_Snapshot::write_to_order( $order, $validated['snapshot'], 'classic_checkout' );
st( 0 === $order->saves, 'snapshot writer leaves final save to WooCommerce lifecycle' );
$snapshot = SPX_Checkout_Address_Snapshot::read_from_order( $order );
st( '101' === $snapshot['ward_id'] && 'v1' === $snapshot['dataset_version'], 'snapshot round-trips through WC_Order CRUD meta' );
st( SPX_Checkout_Address_Snapshot::is_current_and_valid( $order, $service ), 'current canonical snapshot validates' );

$resolved = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
st( true === $resolved['resolved'] && 'classic_checkout' === $resolved['source'], 'resolver preserves Classic source while using checkout snapshot precedence' );

$order->update_meta_data( SPX_Checkout_Address_Snapshot::M_DATASET_VERSION, 'old' );
st( ! SPX_Checkout_Address_Snapshot::is_current_and_valid( $order, $service ), 'stale snapshot is rejected' );
$stale = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
st( false === $stale['resolved'], 'resolver does not use stale snapshot' );

$order->update_meta_data( SPX_Checkout_Address_Snapshot::M_DATASET_VERSION, 'v1' );
SPX_Order_Address::save( $order, $p, $d, '101', $repo, 'admin_override' );
$override = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
st( 'admin_override' === $override['source'], 'admin override remains higher priority than checkout snapshot' );

$fallback_order = new WC_Order();
$fallback = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $fallback_order );
st( 'resolver' === $fallback['source'], 'resolver source is used only when no checkout snapshot or admin override exists' );

echo "\nAll checkout snapshot tests passed ({$GLOBALS['ss_n']} assertions).\n";
