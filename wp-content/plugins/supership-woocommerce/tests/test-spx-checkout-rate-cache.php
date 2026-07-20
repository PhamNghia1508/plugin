<?php
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['spx_transients'] = array();
function get_transient( $key ) { return $GLOBALS['spx_transients'][ $key ]['value'] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['spx_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( $key ) { unset( $GLOBALS['spx_transients'][ $key ] ); return true; }
$GLOBALS['spx_lock_available'] = true;
function wp_cache_add( $key, $value, $group, $ttl ) { if ( ! $GLOBALS['spx_lock_available'] ) { return false; } $GLOBALS['spx_lock_available']=false; return true; }
function wp_cache_delete( $key, $group ) { $GLOBALS['spx_lock_available']=true; return true; }
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-cache.php';
$n = 0;
function t( $condition, $message ) { global $n; $n++; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }
$cache = new SPX_Checkout_Rate_Cache();
$base = array( 'environment'=>'test','sender_location'=>'p1|d1|w1','recipient_location'=>'p2|d2|w2','service_type'=>1,'weight_grams'=>600,'dimensions'=>'20|10|5','cart_hash'=>'cart-a','package_hash'=>'pkg-a','is_cod'=>false,'cod_amount'=>0,'contract_version'=>'6J-X-1','unit_mode'=>'experimental_thousand_vnd' );
$key = $cache->make_key( $base );
t( 0 === strpos( $key, 'spx_rate_' ) && 73 === strlen( $key ), 'cache key is prefixed SHA-256' );
t( false === strpos( $key, 'p1' ) && false === strpos( $key, '090' ), 'cache key exposes neither location nor PII' );
$cache->set( $key, array( 'success'=>true, 'converted_vnd'=>'21000.00' ) );
t( 240 === $GLOBALS['spx_transients'][ $key ]['ttl'], 'cache TTL is four minutes' );
t( '21000.00' === $cache->get( $key )['converted_vnd'], 'sanitized quote round-trips' );
$calls = 0;
$one = $cache->remember( $key, function () use ( &$calls ) { $calls++; return array( 'success'=>true ); } );
t( $one['cache_hit'] && 0 === $calls, 'cache hit avoids callback' );
$miss_key = $cache->make_key( array_merge( $base, array( 'is_cod'=>true, 'cod_amount'=>100000 ) ) );
$two = $cache->remember( $miss_key, function () use ( &$calls ) { $calls++; return array( 'success'=>true, 'source'=>'spx_dynamic' ); } );
t( ! $two['cache_hit'] && 1 === $calls, 'cache miss invokes callback once' );
t( $key !== $miss_key, 'COD changes cache key' );
t( $key !== $cache->make_key( array_merge( $base, array( 'cart_hash'=>'cart-b' ) ) ), 'cart changes cache key' );
t( $key !== $cache->make_key( array_merge( $base, array( 'recipient_location'=>'p2|d2|w3' ) ) ), 'canonical recipient changes cache key' );
t( $key !== $cache->make_key( array_merge( $base, array( 'unit_mode'=>'verified_vnd' ) ) ), 'conversion mode changes cache key' );
$GLOBALS['spx_lock_available']=false; $locked_calls=0;
$locked=$cache->remember($cache->make_key(array_merge($base,array('cart_hash'=>'locked'))),function()use(&$locked_calls){$locked_calls++;return array('success'=>true);});
t(!$locked['success']&&'quote_in_progress'===$locked['error_code']&&0===$locked_calls,'concurrent same-key request is coalesced without a duplicate resolver call');
echo "All checkout rate cache tests passed ($n assertions).\n";
