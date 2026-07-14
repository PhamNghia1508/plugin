<?php
/** Offline updater → mapping integration contract. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['spx_test_options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['spx_test_options'][ $key ] ?? $default; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function __( $v, $d = null ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); }
function wc_get_order_status_name( $s ) { return $s; }
class SPX_Logger { public static function log( $a, $b, $c = 0, $d = '', $e = '' ) {} }
class SPX_Tracking_Service { public static function is_real_tracking( $v ) { return 0 === strpos( $v, 'SPXVN' ); } }
class SPX_Tracking_Scheduler { public static function schedule_canonical_verification( $id ) {} }
class SPX_Tracking_Event_Repository { public function insert( $event ) { return array( 'inserted' => true ); } public function count_by_order( $id ) { return 1; } }
class SPX_Tracking_Route_Parser { public static function parse( $routes, $tracking, $source ) { return $routes; } }
class WC_Order {
	public $meta = array(); public $status = 'processing'; public $notes = array(); public $updates = 0; public $sequence = array();
	public function get_id() { return 99; } public function get_status() { return $this->status; }
	public function get_meta( $k, $s = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function add_order_note( $v ) { $this->notes[] = $v; }
	public function save() { $this->sequence[] = 'save'; }
	public function update_status( $v, $note = '', $manual = false ) { ++$this->updates; $this->status = $v; $this->sequence[] = 'update_status'; }
}
$root = dirname( __DIR__ );
foreach ( array( '/includes/status/class-spx-woo-status-mapping-policy.php', '/includes/status/class-spx-woo-status-mapping-service.php', '/includes/tracking/class-spx-tracking-updater.php' ) as $rel ) {
	if ( ! is_file( $root . $rel ) ) { throw new RuntimeException( 'RED: required mapping/updater component is missing: ' . $rel ); }
	require_once $root . $rel;
}
$n = 0;
function i_ok( $condition, $message ) { global $n; ++$n; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }
function result_for( $code, $internal, $timestamp ) { return array( 'success' => true, 'found' => true, 'tracking_no' => 'SPXVNPHASE6I001', 'status_code' => $code, 'internal_status' => $internal, 'customer_label' => $internal, 'routes' => array( array( 'tracking_no' => 'SPXVNPHASE6I001', 'event_id' => 'e-' . $code, 'status_code' => $code, 'official_status' => $internal, 'internal_status' => $internal, 'customer_label' => $internal, 'customer_message' => $internal, 'event_timestamp' => $timestamp, 'terminal' => '4001' === $code, 'source' => 'manual' ) ) ); }

$order = new WC_Order();
$order->meta['_spx_shipment_status_code'] = '2006'; $order->meta['_spx_shipment_status'] = 'out_for_delivery'; $order->meta['_spx_last_status_event_at'] = 100;
$out = ( new SPX_Tracking_Updater() )->apply( $order, result_for( '4001', 'delivered', 200 ), 'manual' );
i_ok( 'completed' === $order->status && 1 === $order->updates, 'new canonical Delivered status maps through updater' );
i_ok( array_search( 'save', $order->sequence, true ) < array_search( 'update_status', $order->sequence, true ), 'SPX state is saved before Woo status mapping' );
i_ok( '4001' === $order->meta['_spx_last_mapped_status_code'], 'mapping audit is stored by integrated service' );
$updates = $order->updates; $notes = count( $order->notes );
( new SPX_Tracking_Updater() )->apply( $order, result_for( '4001', 'delivered', 200 ), 'manual' );
i_ok( $updates === $order->updates && $notes === count( $order->notes ), 'duplicate canonical status adds no mapping update or note' );

$fault = new class extends WC_Order { public function update_status( $v, $note = '', $manual = false ) { throw new RuntimeException( 'simulated mapping failure' ); } };
$fault->meta['_spx_shipment_status_code'] = '2006'; $fault->meta['_spx_shipment_status'] = 'out_for_delivery'; $fault->meta['_spx_last_status_event_at'] = 100;
$fault_result = ( new SPX_Tracking_Updater() )->apply( $fault, result_for( '4001', 'delivered', 200 ), 'manual' );
i_ok( '4001' === $fault->meta['_spx_shipment_status_code'] && 'delivered' === $fault->meta['_spx_shipment_status'], 'mapping error does not roll back persisted SPX status' );
i_ok( 'error' === $fault_result['mapping']['result'], 'mapping error is isolated and returned without fatal sync' );
echo "All updater mapping integration tests passed ($n assertions).\n";
