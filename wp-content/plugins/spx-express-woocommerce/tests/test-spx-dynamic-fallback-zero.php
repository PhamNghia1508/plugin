<?php
/**
 * PHASE 6G-B0.6 — Dynamic checkout rate resolver fixed-fee contract.
 *
 * The canonical fee resolver must only authorise add_rate when it can produce a
 * valid POSITIVE customer charge. A fixed fallback of 0/empty (unconfigured or
 * corrupted instance settings) must fail closed instead of returning a free rate.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $t, $d = null ) { return $t; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function get_transient( $k ) { return $GLOBALS['spx_transients'][ $k ]['value'] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['spx_transients'][ $k ] = array( 'value' => $v, 'ttl' => $ttl ); return true; }
class SPX_API_Config { public static function for_test() { return new self(); } public function get_environment() { return 'test'; } public function has_account_credentials() { return true; } }
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-request-builder.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-cache.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-dynamic-checkout-rate-service.php';
$n = 0; function t( $c, $m ) { global $n; $n++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: $m\n"; }

$builder = new SPX_Checkout_Rate_Request_Builder( function () { return array( 'valid' => true ); } );
$selection = array( 'province_id' => 'p', 'district_id' => 'd', 'ward_id' => '1', 'dataset_version' => 'v1' );
$sender = array( 'sender_province_code' => 'p', 'sender_district_code' => 'd', 'sender_ward_code' => '1' );
$ctx = array( 'selection' => $selection, 'sender' => $sender, 'is_cod' => false, 'cod_amount' => 0 );
$gate_disabled = array( 'dynamic_flag' => false );
$package = array( 'contents' => array(), 'contents_cost' => 400000 );

/* Positive fixed fallback is charged. */
$svc = new SPX_Dynamic_Checkout_Rate_Service( $builder, new SPX_Checkout_Rate_Cache(), null, $gate_disabled );
$r = $svc->calculate( $package, 30000, $ctx );
t( true === $r['add_rate'] && '30000.00' === $r['cost'], 'positive fixed fallback authorises a positive charge' );

/* Zero fixed fallback must NOT authorise a free rate. */
$r0 = $svc->calculate( $package, 0, $ctx );
t( false === $r0['add_rate'] && null === $r0['cost'], 'zero fixed fallback fails closed (no free rate)' );
t( 'fixed_unconfigured' === $r0['audit']['_spx_rate_fallback_reason'], 'zero fixed fallback records an admin-safe reason' );

/* Empty/invalid fixed fallback must NOT authorise a free rate. */
$re = $svc->calculate( $package, '', $ctx );
t( false === $re['add_rate'] && null === $re['cost'], 'empty fixed fallback fails closed' );

echo "All dynamic fallback zero-guard tests passed ($n assertions).\n";
