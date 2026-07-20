<?php
define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
require_once dirname( __DIR__ ) . '/includes/production/class-spx-environment.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-order-environment.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-environment-router.php';
class FakeOrder { public $meta = array(); public function get_meta( $k, $single = true ) { return isset( $this->meta[$k] ) ? $this->meta[$k] : ''; } public function update_meta_data( $k, $v ) { $this->meta[$k]=$v; } }
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
$legacy = new FakeOrder(); $legacy->meta['_spx_shipment_environment'] = 'test';
e( 'test' === SPX_Order_Environment::get( $legacy ), 'legacy sandbox order remains test' );
e( SPX_Order_Environment::bind( $legacy, 'test' ), 'same environment binding is idempotent' );
e( ! SPX_Order_Environment::bind( $legacy, 'production' ), 'shipment environment is immutable' );
$prod = new FakeOrder(); e( SPX_Order_Environment::bind( $prod, 'production' ), 'new order can bind production explicitly' );
e( 'production' === $prod->meta['_spx_environment'], 'canonical meta is persisted' );
e( 'test' === SPX_Environment_Router::single_batch_environment( array( $legacy ) ), 'single environment batch routes' );
e( '' === SPX_Environment_Router::single_batch_environment( array( $legacy, $prod ) ), 'mixed environment batch is rejected' );
e( ! SPX_Environment_Router::production_operation_allowed( 'create' ), 'production create is blocked in 6G-A' );
$locked = new FakeOrder(); $locked->meta['_spx_tracking_number'] = 'SPXVN060000000099';
e( ! SPX_Order_Environment::bind( $locked, 'production' ), 'unbound existing shipment marker cannot be assigned a guessed environment' );
$prod->meta['_spx_tracking_number'] = 'SPXVN060000000098';
e( 'production' === SPX_Order_Environment::get( $prod ), 'production tracking stays production' );
echo "Environment routing tests passed.\n";
