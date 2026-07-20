<?php
/** Offline mapping-service behavior tests. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['spx_test_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['spx_test_options'] ) ? $GLOBALS['spx_test_options'][ $key ] : $default; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function gmdate_i18n( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); }
function __( $text, $domain = null ) { return $text; }
function wc_get_order_status_name( $status ) { return ucwords( str_replace( '-', ' ', $status ) ); }
class SPX_Logger { public static $entries = array(); public static function log( $level, $event, $order_id = 0, $tracking = '', $context = '' ) { self::$entries[] = compact( 'level', 'event', 'order_id', 'context' ); } }
class WC_Order {
	public $id; public $status; public $meta = array(); public $notes = array(); public $updates = 0; public $saves = 0;
	public $total = 100000; public $shipping = 15000; public $payment = 'cod'; public $refunds = array(); public $on_update;
	public function __construct( $id, $status ) { $this->id = $id; $this->status = $status; }
	public function get_id() { return $this->id; } public function get_status() { return $this->status; }
	public function update_status( $status, $note = '', $manual = false ) { ++$this->updates; $this->status = $status; if ( is_callable( $this->on_update ) ) { call_user_func( $this->on_update ); } }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { ++$this->saves; }
	public function get_total() { return $this->total; } public function get_shipping_total() { return $this->shipping; }
	public function get_payment_method() { return $this->payment; } public function get_refunds() { return $this->refunds; }
}

$base = dirname( __DIR__ ) . '/includes/status/';
foreach ( array( 'class-spx-woo-status-mapping-policy.php', 'class-spx-woo-status-mapping-service.php' ) as $name ) {
	if ( ! is_file( $base . $name ) ) { throw new RuntimeException( 'RED: ' . $name . ' does not exist yet.' ); }
	require_once $base . $name;
}
$n = 0;
function s_ok( $condition, $message ) { global $n; ++$n; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }
function mapped( $code, $woo = 'processing', $id = 1, $source = 'manual' ) { $order = new WC_Order( $id, $woo ); $result = ( new SPX_Woo_Status_Mapping_Service() )->apply( $order, '2001', $code, $source, 1700000000 ); return array( $order, $result ); }

foreach ( array( 'pending', 'processing', 'on-hold' ) as $source_status ) { list( $o, $r ) = mapped( '4001', $source_status, 10 + $n ); s_ok( 'completed' === $o->status && 'applied' === $r['result'], "Delivered maps $source_status to completed" ); }
foreach ( array( 'cancelled', 'refunded', 'failed', 'trash' ) as $source_status ) { list( $o, $r ) = mapped( '4001', $source_status, 20 + $n ); s_ok( $source_status === $o->status && 'protected_status' === $r['result'], "Delivered protects $source_status" ); }
foreach ( array( '3001', '5002', '5003', '6001' ) as $code ) { list( $o, $r ) = mapped( $code, 'processing', 30 + $n ); s_ok( 'on-hold' === $o->status, "$code maps processing to on-hold" ); }
foreach ( array( 'completed', 'cancelled', 'refunded', 'failed' ) as $source_status ) { list( $o, $r ) = mapped( '5003', $source_status, 40 + $n ); s_ok( $source_status === $o->status && 'protected_status' === $r['result'], "exception protects $source_status" ); }

list( $disabled, $disabled_result ) = mapped( '7001', 'processing', 50 );
s_ok( 'processing' === $disabled->status && 'disabled' === $disabled_result['result'], 'Cancelled defaults disabled' );
$GLOBALS['spx_test_options'][ SPX_Woo_Status_Mapping_Policy::OPTION ] = array_merge( SPX_Woo_Status_Mapping_Policy::defaults(), array( 'map_7001' => 'yes', 'map_6003' => 'yes' ) );
list( $cancelled, $cancel_result ) = mapped( '7001', 'processing', 51 );
s_ok( 'cancelled' === $cancelled->status && 0 === count( $cancelled->refunds ), 'enabled Cancelled maps eligible order without refund' );
list( $returned, $returned_result ) = mapped( '6003', 'processing', 52 );
s_ok( 'on-hold' === $returned->status && 0 === count( $returned->refunds ), 'Returned can map to on-hold and never refunds' );

$same_target = new WC_Order( 53, 'completed' );
$same_result = ( new SPX_Woo_Status_Mapping_Service() )->apply( $same_target, '2001', '4001', 'manual', 1 );
s_ok( 0 === $same_target->updates && 'already_applied' === $same_result['result'], 'same target causes no status update' );
$same_code = new WC_Order( 54, 'processing' );
$same_code_result = ( new SPX_Woo_Status_Mapping_Service() )->apply( $same_code, '4001', '4001', 'manual', 1 );
s_ok( 0 === $same_code->updates && 'same_status' === $same_code_result['result'], 'same SPX code is ignored' );

$manual = new WC_Order( 55, 'processing' ); $svc = new SPX_Woo_Status_Mapping_Service();
$svc->apply( $manual, '2001', '5003', 'scheduler', 1 ); $manual->status = 'processing';
$replay = $svc->apply( $manual, '2001', '5003', 'scheduler', 1 );
s_ok( 1 === $manual->updates && 'processing' === $manual->status && 'manual_override_preserved' === $replay['result'], 'manual override survives same SPX replay' );
$next = $svc->apply( $manual, '5003', '4001', 'scheduler', 2 );
s_ok( 2 === $manual->updates && 'completed' === $manual->status && 'applied' === $next['result'], 'new Delivered transition may map after manual override' );
s_ok( 2 === count( $manual->notes ), 'one plugin note is added per applied transition only' );
s_ok( '4001' === $manual->meta['_spx_last_mapped_status_code'] && 'completed' === $manual->meta['_spx_last_mapped_woo_status'], 'audit metadata persisted' );
s_ok( 1 === $manual->meta['_spx_last_mapping_settings_version'], 'audit stores settings version' );
s_ok( 100000 === $manual->total && 15000 === $manual->shipping && 'cod' === $manual->payment && 0 === count( $manual->refunds ), 'totals, shipping, payment and refunds remain unchanged' );

$reentrant = new WC_Order( 56, 'processing' ); $reentrant_service = new SPX_Woo_Status_Mapping_Service(); $inner = null;
$reentrant->on_update = function () use ( $reentrant_service, $reentrant, &$inner ) { $inner = $reentrant_service->apply( $reentrant, '2001', '4001', 'manual', 1 ); };
$reentrant_service->apply( $reentrant, '2001', '4001', 'manual', 1 );
s_ok( 1 === $reentrant->updates && 'in_progress' === $inner['result'], 'per-order reentrancy guard prevents loops' );
list( $unknown_source ) = mapped( '4001', 'processing', 57, 'raw<script>' );
s_ok( 'unknown' === $unknown_source->meta['_spx_last_mapping_source'], 'untrusted source normalizes to unknown' );
echo "All mapping service tests passed ($n assertions).\n";

