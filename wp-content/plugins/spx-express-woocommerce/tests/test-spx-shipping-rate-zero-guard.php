<?php
/**
 * PHASE 6G-B0.6 — Shipping-rate zero/FREE guard.
 *
 * Reproduces the staging defect where the SPX rate is materialised with cost 0
 * (checkout shows "FREE") whenever the fixed fee resolves to 0/empty/invalid,
 * and pins the Fixed Rate Contract: an unconfigured/empty/invalid fixed fee must
 * NOT become a silent free-shipping rate — it must fail closed. An explicit
 * free-shipping threshold remains the only legitimate zero-cost SPX rate.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $t, $d = null ) { return $t; }
function wc_format_decimal( $v ) { return is_numeric( $v ) ? (string) $v : '0'; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function add_action() {}
$GLOBALS['spx_notices'] = array();
function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['spx_notices'][] = array( $message, $type ); }
function wc_has_notice( $message, $type = 'success' ) { foreach ( $GLOBALS['spx_notices'] as $n ) { if ( $n[0] === $message && $n[1] === $type ) { return true; } } return false; }
class WC_Shipping_Method {
	public $id, $instance_id, $method_title, $method_description, $supports, $enabled = 'yes', $title = 'Old';
	public $rates = array(); public $options = array(); public $instance_form_fields = array();
	public function init_instance_form_fields() {} public function init_settings() {}
	public function get_option( $k, $d = '' ) { return $this->options[ $k ] ?? $d; }
	public function get_rate_id() { return 'spx_express:6'; }
	public function add_rate( $r ) { $this->rates[] = $r; }
}
class SPX_Stub_Rate_Service { public $result; public function calculate( $package, $fixed, $context = array() ) { return $this->result; } }
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-shipping-method.php';
$n = 0; function t( $c, $m ) { global $n; $n++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: $m\n"; }

/* 1. Positive fixed fee must be charged (no FREE). */
$svc = new SPX_Stub_Rate_Service();
$svc->result = array( 'add_rate' => true, 'source' => 'fixed_disabled_experiment', 'cost' => '30000.00', 'audit' => array() );
$m = new SPX_Shipping_Method( 6, $svc );
$m->options = array( 'base_cost' => '30000', 'free_shipping_min_amount' => '500000' );
$m->calculate_shipping( array( 'contents_cost' => 400000 ) );
t( 1 === count( $m->rates ) && '30000.00' === $m->rates[0]['cost'], 'positive fixed fee is charged (not FREE)' );

/* 2. Fixed fee resolves to 0 (corrupted/unreadable instance settings default) -> must NOT add a FREE rate. */
$GLOBALS['spx_notices'] = array();
$svc->result = array( 'add_rate' => true, 'source' => 'fixed_disabled_experiment', 'cost' => '0.00', 'audit' => array() );
$m = new SPX_Shipping_Method( 6, $svc );
$m->options = array( 'base_cost' => '0', 'free_shipping_min_amount' => '' ); // WC defaults after unserialize() failure
$m->calculate_shipping( array( 'contents_cost' => 400000 ) );
t( 0 === count( $m->rates ), 'zero fixed fee does NOT materialise a FREE rate (fail closed)' );

/* 3. Fixed fee resolves to null (fail-closed policy) -> no rate. */
$svc->result = array( 'add_rate' => false, 'source' => 'fallback_fixed', 'cost' => null, 'audit' => array() );
$m = new SPX_Shipping_Method( 6, $svc );
$m->options = array( 'base_cost' => '0' );
$m->calculate_shipping( array( 'contents_cost' => 400000 ) );
t( 0 === count( $m->rates ), 'null fixed fee omits rate' );

/* 4. Non-numeric candidate cost -> no free rate. */
$svc->result = array( 'add_rate' => true, 'source' => 'fixed_disabled_experiment', 'cost' => '', 'audit' => array() );
$m = new SPX_Shipping_Method( 6, $svc );
$m->options = array( 'base_cost' => '' );
$m->calculate_shipping( array( 'contents_cost' => 400000 ) );
t( 0 === count( $m->rates ), 'empty candidate cost omits rate' );

/* 5. Explicit free-shipping threshold remains the only legitimate zero-cost rate. */
$svc->result = array( 'add_rate' => true, 'source' => 'x', 'cost' => '99999.00', 'audit' => array() );
$m = new SPX_Shipping_Method( 6, $svc );
$m->options = array( 'base_cost' => '30000', 'free_shipping_min_amount' => '300000' );
$m->calculate_shipping( array( 'contents_cost' => 400000 ) ); // 400000 >= 300000 -> free
t( 1 === count( $m->rates ) && 0.0 === $m->rates[0]['cost'], 'free-shipping threshold still yields an intentional 0-cost rate' );

echo "All zero-guard tests passed ($n assertions).\n";
