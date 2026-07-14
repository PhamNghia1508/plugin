<?php
/** Offline policy contract tests. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['spx_test_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['spx_test_options'] ) ? $GLOBALS['spx_test_options'][ $key ] : $default; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }

$file = dirname( __DIR__ ) . '/includes/status/class-spx-woo-status-mapping-policy.php';
if ( ! is_file( $file ) ) { throw new RuntimeException( 'RED: status mapping policy does not exist yet.' ); }
require_once $file;

$n = 0;
function p_ok( $condition, $message ) { global $n; ++$n; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } echo "PASS: $message\n"; }

$defaults = SPX_Woo_Status_Mapping_Policy::defaults();
p_ok( 'yes' === $defaults['master_enabled'], 'master defaults ON' );
foreach ( array( '3001', '4001', '5002', '5003', '6001' ) as $code ) { p_ok( 'yes' === $defaults[ 'map_' . $code ], "$code defaults ON" ); }
foreach ( array( '5001', '6002', '6003', '7001' ) as $code ) { p_ok( 'no' === $defaults[ 'map_' . $code ], "$code defaults OFF" ); }

$expected = array(
	'1001' => '', '2001' => '', '2006' => '', '3001' => 'on-hold', '4001' => 'completed',
	'5001' => 'on-hold', '5002' => 'on-hold', '5003' => 'on-hold', '6001' => 'on-hold',
	'6002' => 'on-hold', '6003' => 'on-hold', '7001' => 'cancelled',
);
foreach ( $expected as $code => $target ) { p_ok( $target === SPX_Woo_Status_Mapping_Policy::mapping( $code )['target'], "$code has immutable target" ); }
p_ok( array( 'pending', 'on-hold', 'processing' ) === SPX_Woo_Status_Mapping_Policy::mapping( '4001' )['allowed_sources'], 'Delivered source allowlist is exact' );
p_ok( array( 'pending', 'processing' ) === SPX_Woo_Status_Mapping_Policy::mapping( '5003' )['allowed_sources'], 'exception source allowlist is exact' );

$clean = SPX_Woo_Status_Mapping_Policy::sanitize( array(
	'master_enabled' => 'yes', 'map_4001' => 'yes', 'map_7001' => 'yes',
	'map_6003' => array( 'refunded' ), 'target' => 'failed', 'map_9999' => 'yes',
) );
p_ok( ! isset( $clean['target'], $clean['map_9999'] ), 'malformed and unknown keys are discarded' );
p_ok( 'no' === $clean['map_6003'], 'nested/non-boolean mapping value is rejected without coercion' );
p_ok( 'completed' === SPX_Woo_Status_Mapping_Policy::mapping( '4001', $clean )['target'], 'request cannot replace target with refunded/failed' );
p_ok( '' === SPX_Woo_Status_Mapping_Policy::mapping( '9999' )['target'], 'unknown SPX status is no_action' );
p_ok( 1 === SPX_Woo_Status_Mapping_Policy::SETTINGS_VERSION, 'settings version is 1' );
echo "All mapping policy tests passed ($n assertions).\n";
