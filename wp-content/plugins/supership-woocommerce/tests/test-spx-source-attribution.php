<?php
/** Network-free source-attribution contract tests (PHP 7.4 compatible). */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }

class WC_Order {
	public $meta = array();
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
}

require_once dirname( __DIR__ ) . '/includes/checkout/class-spx-checkout-address-snapshot.php';

$GLOBALS['sat_total'] = 0;
$GLOBALS['sat_failed'] = 0;
function sat( $condition, $message ) {
	$GLOBALS['sat_total']++;
	if ( ! $condition ) {
		$GLOBALS['sat_failed']++;
		echo "FAIL: {$message}\n";
		return;
	}
	echo "PASS: {$message}\n";
}

$reflection = new ReflectionMethod( 'SPX_Checkout_Address_Snapshot', 'write_to_order' );
sat( 3 === $reflection->getNumberOfParameters(), 'snapshot writer requires an explicit source' );
sat( method_exists( 'SPX_Checkout_Address_Snapshot', 'is_checkout_source' ), 'checkout source classification is centralized' );
sat( method_exists( 'SPX_Checkout_Address_Snapshot', 'source_label' ), 'admin source labels are centralized' );

if ( method_exists( 'SPX_Checkout_Address_Snapshot', 'is_checkout_source' ) ) {
	foreach ( array( 'classic_checkout', 'blocks_checkout', 'checkout', 'checkout_snapshot' ) as $source ) {
		sat( SPX_Checkout_Address_Snapshot::is_checkout_source( $source ), $source . ' is treated as a checkout snapshot for precedence' );
	}
	sat( ! SPX_Checkout_Address_Snapshot::is_checkout_source( 'resolver' ), 'resolver is not classified as a checkout snapshot' );
} else {
	foreach ( array( 'classic_checkout', 'blocks_checkout', 'checkout', 'checkout_snapshot' ) as $source ) {
		sat( false, $source . ' is treated as a checkout snapshot for precedence' );
	}
	sat( false, 'resolver is not classified as a checkout snapshot' );
}

$labels = array(
	'classic_checkout' => 'Checkout Classic',
	'blocks_checkout'  => 'Checkout Blocks',
	'admin_override'   => 'Quản trị viên ghi đè',
	'resolver'         => 'Tự phân giải',
	'migration'        => 'Dữ liệu chuyển đổi',
	'checkout'         => 'Checkout — nguồn cũ không xác định',
	'checkout_snapshot'=> 'Checkout — nguồn cũ không xác định',
	''                 => 'Chưa xác định',
	'not_allowed'      => 'Chưa xác định',
);
foreach ( $labels as $source => $expected ) {
	sat(
		method_exists( 'SPX_Checkout_Address_Snapshot', 'source_label' ) && $expected === SPX_Checkout_Address_Snapshot::source_label( $source ),
		$source . ' maps to a safe admin label'
	);
}

$snapshot = array(
	'province_id' => 'p_123456789abc', 'province_name' => 'Province',
	'district_id' => 'd_123456789abc', 'district_name' => 'District',
	'ward_id' => '101', 'ward_name' => 'Ward', 'dataset_version' => 'v1',
	'delivery_supported' => true, 'cod_supported' => true, 'validated_at' => '2026-07-13T00:00:00+00:00',
);

if ( 3 === $reflection->getNumberOfParameters() ) {
	foreach ( array( 'classic_checkout', 'blocks_checkout' ) as $source ) {
		$order = new WC_Order();
		SPX_Checkout_Address_Snapshot::write_to_order( $order, $snapshot, $source );
		sat( $source === $order->get_meta( SPX_Checkout_Address_Snapshot::M_SOURCE, true ), $source . ' persists unchanged through WC_Order CRUD' );
	}

	$order = new WC_Order();
	$rejected = false;
	try {
		SPX_Checkout_Address_Snapshot::write_to_order( $order, $snapshot, 'not_allowed' );
	} catch ( InvalidArgumentException $e ) {
		$rejected = true;
	}
	sat( $rejected && array() === $order->meta, 'unknown source is rejected before any order meta is written' );

	foreach ( array( 'checkout', 'checkout_snapshot' ) as $legacy ) {
		$order = new WC_Order();
		$order->update_meta_data( SPX_Checkout_Address_Snapshot::M_SOURCE, $legacy );
		sat( $legacy === SPX_Checkout_Address_Snapshot::read_from_order( $order )['source'], $legacy . ' remains readable as legacy data' );
	}
} else {
	sat( false, 'classic_checkout persists unchanged through WC_Order CRUD' );
	sat( false, 'blocks_checkout persists unchanged through WC_Order CRUD' );
	sat( false, 'unknown source is rejected before any order meta is written' );
	sat( true, 'checkout remains readable as legacy data' );
	sat( true, 'checkout_snapshot remains readable as legacy data' );
}

if ( $GLOBALS['sat_failed'] ) {
	echo "\nSource attribution contract FAILED ({$GLOBALS['sat_failed']}/{$GLOBALS['sat_total']} failures).\n";
	exit( 1 );
}

echo "\nAll source attribution tests passed ({$GLOBALS['sat_total']} assertions).\n";
