<?php
define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
function get_option( $key, $default = false ) { return $default; }
function wp_parse_url( $url ) { return parse_url( $url ); }
require_once dirname( __DIR__ ) . '/includes/production/class-spx-environment.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-secret-storage.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-credential-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-spx-api-config.php';
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
e( 'https://test-stable.spx.vn/' === SPX_Environment::base_url( 'test' ), 'test URL is fixed' );
e( 'https://spx.vn/' === SPX_Environment::base_url( 'production' ), 'production URL is fixed' );
e( '' === SPX_Environment::normalize( 'staging' ), 'unknown environment fails closed' );
e( SPX_Environment::is_allowed_endpoint( '/open/api/v1/order/batch_search_order' ), 'implemented endpoint is allowed' );
e( ! SPX_Environment::is_allowed_endpoint( '/open/api/v1/order/anything' ), 'endpoint prefix alone is insufficient' );
putenv( 'SPX_TEST_APP_ID=test-id' ); putenv( 'SPX_TEST_APP_SECRET=test-secret' );
putenv( 'SPX_PRODUCTION_APP_ID=prod-id' ); putenv( 'SPX_PRODUCTION_APP_SECRET=prod-secret' );
$test = SPX_API_Config::for_environment( 'test' ); $prod = SPX_API_Config::for_environment( 'production' );
e( 'test-id' === $test->get_app_id() && 'prod-id' === $prod->get_app_id(), 'credentials stay environment-specific' );
e( 'https://spx.vn/' === $prod->get_base_url(), 'config cannot override production host' );
foreach ( array( 'SPX_TEST_APP_ID', 'SPX_TEST_APP_SECRET', 'SPX_PRODUCTION_APP_ID', 'SPX_PRODUCTION_APP_SECRET' ) as $name ) { putenv( $name ); }
echo "Environment config tests passed.\n";
