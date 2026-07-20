<?php
/** PHASE 6G-B0.5 canonical payment/COD contract (network-free). */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

$count = 0;
$failed = 0;
function payment_assert( $condition, $message ) {
	global $count, $failed;
	++$count;
	if ( ! $condition ) { ++$failed; echo "FAIL: {$message}\n"; return; }
	echo "PASS: {$message}\n";
}

class WC_Order {
	private $method;
	private $paid;
	private $status;
	private $total;
	private $shipping;
	public function __construct( $method, $paid, $status, $total, $shipping = 30000 ) {
		$this->method = $method;
		$this->paid = $paid;
		$this->status = $status;
		$this->total = $total;
		$this->shipping = $shipping;
	}
	public function get_payment_method() { return $this->method; }
	public function is_paid() { return $this->paid; }
	public function needs_payment() { return ! $this->paid; }
	public function get_status() { return $this->status; }
	public function get_total() { return $this->total; }
	public function get_shipping_total() { return $this->shipping; }
}

$resolver_file = dirname( __DIR__ ) . '/includes/class-spx-payment-resolver.php';
payment_assert( is_file( $resolver_file ), 'canonical payment resolver exists' );
if ( is_file( $resolver_file ) ) {
	require_once $resolver_file;

	$paid = new WC_Order( 'qr_gateway', true, 'processing', 321000 );
	$before = array( $paid->get_total(), $paid->get_shipping_total(), $paid->get_status() );
	$r = SPX_Payment_Resolver::resolve( $paid );
	payment_assert( true === $r['ready_for_shipment'] && 'paid' === $r['payment_state'] && 0 === $r['cod_amount'], 'paid online resolves ready with COD zero' );
	payment_assert( $before === array( $paid->get_total(), $paid->get_shipping_total(), $paid->get_status() ), 'paid resolution leaves order and shipping totals/status unchanged' );

	$cod = new WC_Order( 'cod', false, 'on-hold', 321000 );
	$cod_before = array( $cod->get_total(), $cod->get_shipping_total(), $cod->get_status() );
	$r = SPX_Payment_Resolver::resolve( $cod );
	payment_assert( true === $r['ready_for_shipment'] && 'cash_on_delivery' === $r['payment_state'] && 321000 === $r['cod_amount'], 'unpaid COD resolves full valid order total' );
	payment_assert( $cod_before === array( $cod->get_total(), $cod->get_shipping_total(), $cod->get_status() ), 'COD resolution leaves the configured shipping fee and order total/status unchanged' );

	$online = SPX_Payment_Resolver::resolve( new WC_Order( 'qr_gateway', false, 'pending', 321000 ) );
	payment_assert( false === $online['ready_for_shipment'] && null === $online['cod_amount'] && 'payment_not_confirmed' === $online['reason'], 'unpaid online payment is blocked' );

	$missing = SPX_Payment_Resolver::resolve( new WC_Order( '', false, 'pending', 321000 ) );
	payment_assert( false === $missing['ready_for_shipment'] && null === $missing['cod_amount'] && 'payment_method_missing' === $missing['reason'], 'missing payment method is blocked' );

	foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
		$r = SPX_Payment_Resolver::resolve( new WC_Order( 'cod', false, $status, 321000 ) );
		payment_assert( false === $r['ready_for_shipment'] && null === $r['cod_amount'] && 'order_status_blocked' === $r['reason'], $status . ' order is blocked' );
	}

	foreach ( array( null, -1, 'not-numeric' ) as $invalid ) {
		$r = SPX_Payment_Resolver::resolve( new WC_Order( 'cod', false, 'on-hold', $invalid ) );
		payment_assert( false === $r['ready_for_shipment'] && null === $r['cod_amount'] && 'invalid_order_total' === $r['reason'], 'invalid order total fails closed' );
	}
}

if ( $failed ) { echo "SPX payment resolver RED/GREEN suite FAILED ({$failed}/{$count}).\n"; exit( 1 ); }
echo "SPX payment resolver PASS ({$count} assertions).\n";
