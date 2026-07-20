<?php
/** RC11 — canonical recipient-name resolution. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }

class WC_Order {
	private $meta;
	private $shipping_first;
	private $shipping_last;
	private $billing_first;
	private $billing_last;
	public function __construct( array $data ) {
		$this->meta = $data['meta'] ?? '';
		$this->shipping_first = $data['shipping_first'] ?? '';
		$this->shipping_last = $data['shipping_last'] ?? '';
		$this->billing_first = $data['billing_first'] ?? '';
		$this->billing_last = $data['billing_last'] ?? '';
	}
	public function get_meta( $key, $single = true ) { return '_spx_billing_full_name' === $key ? $this->meta : ''; }
	public function get_shipping_first_name() { return $this->shipping_first; }
	public function get_shipping_last_name() { return $this->shipping_last; }
	public function get_billing_first_name() { return $this->billing_first; }
	public function get_billing_last_name() { return $this->billing_last; }
}

$path = dirname( __DIR__ ) . '/includes/class-spx-order-name-resolver.php';
if ( is_file( $path ) ) { require_once $path; }

$n = 0;
function t( $condition, $message ) {
	global $n;
	$n++;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo "PASS: {$message}\n";
}

t( class_exists( 'SPX_Order_Name_Resolver' ), 'canonical order-name resolver exists' );

$meta_order = new WC_Order( array(
	'meta' => '  Nguyễn   Văn A  ',
	'shipping_first' => 'A',
	'shipping_last' => 'Nguyễn Văn',
	'billing_first' => 'Wrong',
) );
t( 'Nguyễn Văn A' === SPX_Order_Name_Resolver::recipient_name( $meta_order ), 'exact _spx_billing_full_name has first priority and is normalized without reordering' );

$shipping_order = new WC_Order( array(
	'shipping_first' => 'Trần Thị Bích Ngọc',
	'shipping_last' => '',
	'billing_first' => 'Fallback',
) );
t( 'Trần Thị Bích Ngọc' === SPX_Order_Name_Resolver::recipient_name( $shipping_order ), 'shipping name is the second priority' );

$billing_order = new WC_Order( array(
	'billing_first' => 'Lê Minh',
	'billing_last' => '',
) );
t( 'Lê Minh' === SPX_Order_Name_Resolver::recipient_name( $billing_order ), 'billing name is the final fallback' );

echo "All canonical order-name tests passed ({$n} assertions).\n";
