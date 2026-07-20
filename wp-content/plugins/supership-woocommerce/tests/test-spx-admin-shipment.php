<?php
/** Lightweight tests for the Gate B WC_Order persistence boundary. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return (string) $url; }
function current_user_can() { return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}

class WC_Order {
	public $meta = array();
	public $notes = array();
	public $saves = 0;
	public function get_id() { return 55; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saves++; }
}
class SPX_Logger { public static function log() {} }
class SPX_Tracking_Service {}
class SPX_Shipment_Service {
	public static function is_real_tracking( string $tracking ): bool { return 0 !== stripos( $tracking, 'SPXMOCK' ) && (bool) preg_match( '/^SPX[A-Z0-9]+$/', $tracking ); }
}
class SPX_Tracking_Status_Mapper {
	public static function map( $code ): array { return array( 'customer_label' => 'Chờ lấy hàng' ); }
}

require_once dirname( __DIR__ ) . '/includes/admin/class-spx-admin-shipment.php';

$GLOBALS['n'] = 0;
function t( $condition, $message ) {
	$GLOBALS['n']++;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo "PASS: {$message}\n";
}

$order = new WC_Order();
t( '' === SPX_Admin_Shipment::create_guard_reason( $order ), 'fresh order is eligible for one create attempt' );
t( true === SPX_Admin_Shipment::acquire_create_lock( $order, 'WC-1-T-55' ), 'first concurrent create request acquires the atomic lock' );
t( false === SPX_Admin_Shipment::acquire_create_lock( $order, 'WC-1-T-55' ), 'second concurrent create request cannot acquire the atomic lock' );
SPX_Admin_Shipment::prepare_create_attempt( $order, 'WC-1-T-55' );
t( 'already_attempted' === SPX_Admin_Shipment::create_guard_reason( $order ), 'durable attempt marker blocks a second create' );
t( 'WC-1-T-55' === $order->get_meta( SPX_Admin_Shipment::M_CLIENT_ID, true ), 'attempt stores deterministic client order id before network' );
t( 'attempting' === $order->get_meta( SPX_Admin_Shipment::M_STATE, true ) && 'test' === $order->get_meta( SPX_Admin_Shipment::M_ENV, true ), 'attempt stores test environment and attempting state' );

$apply = new ReflectionMethod( 'SPX_Admin_Shipment', 'apply_create_result' );
$parameters = $apply->getParameters();
t( $apply->isPublic(), 'create result persistence is a public integration seam' );
t( 4 === count( $parameters ) && $parameters[3]->isOptional(), 'duplicate recovery service can be safely injected' );
$apply->setAccessible( true );
$apply->invoke( null, $order, 'WC-1-T-55', array(
	'success' => true, 'state' => 'created', 'tracking_no' => 'SPXVN123456789',
	'tracking_link' => 'https://test.spx.vn/track/SPXVN123456789', 'created_at' => '2026-07-13T00:00:00Z',
	'fees' => array( 'fee_unit' => 'unconfirmed', 'currency' => '', 'estimated_shipping_fee' => 21, 'vat_fee' => 1.56, 'unexpected' => 'drop-me' ),
) );
t( 'SPXVN123456789' === $order->get_meta( SPX_Admin_Shipment::M_TRACKING, true ), 'real tracking persists through WC_Order CRUD' );
$fees = $order->get_meta( SPX_Admin_Shipment::M_FEES, true );
t( 'unconfirmed' === $fees['fee_unit'] && 21 === $fees['estimated_shipping_fee'] && 1.56 === $fees['vat_fee'], 'create fees persist raw with unconfirmed unit' );
t( ! isset( $fees['unexpected'] ), 'fee persistence uses an allowlist, not a raw response' );
t( '' !== SPX_Admin_Shipment::create_guard_reason( $order ), 'created order remains permanently duplicate-protected' );

$unknown = new WC_Order();
SPX_Admin_Shipment::prepare_create_attempt( $unknown, 'WC-1-T-56' );
$apply->invoke( null, $unknown, 'WC-1-T-56', array( 'success' => false, 'state' => 'unknown' ) );
t( 'unknown' === $unknown->get_meta( SPX_Admin_Shipment::M_STATE, true ), 'unknown result persists unknown state' );
t( '' === $unknown->get_meta( SPX_Admin_Shipment::M_TRACKING, true ), 'unknown result never creates fake tracking' );
t( 'already_attempted' === SPX_Admin_Shipment::create_guard_reason( $unknown ), 'unknown result cannot be retried via Create' );

echo "\nAll SPX admin shipment tests passed ({$GLOBALS['n']} assertions).\n";
