<?php
/**
 * Lightweight, network-free tests for the SPX API foundation classes.
 * Runs on plain PHP 7.4 (no WordPress) with minimal stubs, like tests/test-core.php.
 * The HTTP transport is stubbed via a controllable wp_remote_post() so no real
 * network call is ever made here.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:7.4-cli \
 *     php wp-content/plugins/spx-express-woocommerce/tests/test-spx-api-foundation.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ );

/* ---- Minimal WordPress stubs -------------------------------------------- */
function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url ); }

class WP_Error {
	private $msg;
	public function __construct( $code = '', $message = '' ) { $this->msg = $message; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/* Controllable HTTP transport stub. */
$GLOBALS['spx_http'] = array( 'response' => null, 'last_args' => null, 'calls' => 0 );
function spx_http_set( $response ) { $GLOBALS['spx_http']['response'] = $response; $GLOBALS['spx_http']['last_args'] = null; $GLOBALS['spx_http']['calls'] = 0; }
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['spx_http']['last_args'] = array( 'url' => $url ) + $args;
	$GLOBALS['spx_http']['calls']++;
	$r = $GLOBALS['spx_http']['response'];
	return null === $r ? new WP_Error( 'no_stub', 'no stubbed response' ) : $r;
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : ''; }
function spx_http_ok( $ret_code, $data = null, $status = 200 ) {
	$body = array( 'ret_code' => $ret_code, 'message' => 'success.' );
	if ( null !== $data ) { $body['data'] = $data; }
	return array( 'response' => array( 'code' => $status ), 'body' => json_encode( $body ) );
}

/* ---- Load production classes under test --------------------------------- */
$api = dirname( __DIR__ ) . '/includes/api/';
$production = dirname( __DIR__ ) . '/includes/production/';
require_once $production . 'class-spx-environment.php';
require_once $production . 'class-spx-secret-storage.php';
require_once $production . 'class-spx-credential-store.php';
require_once $api . 'class-spx-api-error-mapper.php';
require_once $api . 'class-spx-api-response.php';
require_once $api . 'class-spx-api-config.php';
require_once $api . 'class-spx-request-signer.php';
require_once $api . 'interface-spx-http-client.php';
require_once $api . 'class-spx-http-client.php';
require_once $api . 'class-spx-account-service.php';

/* ---- Tiny assert harness ------------------------------------------------ */
$GLOBALS['spx_tests'] = 0;
function t( $cond, $msg ) {
	$GLOBALS['spx_tests']++;
	if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); }
	echo "PASS: {$msg}\n";
}
function with_env( array $vars, callable $fn ) {
	foreach ( $vars as $k => $v ) { putenv( $k . '=' . $v ); }
	try { $fn(); } finally { foreach ( $vars as $k => $v ) { putenv( $k ); } }
}

/* Official signature test vector (public, from SPX docs). */
$V_APP_ID   = '100000';
$V_SECRET   = 'H25HY53GO4BA2GQ';
$V_TS       = 1677918414;
$V_RND       = 926611981;
$V_PAYLOAD  = array( 'user_id' => 239404781503925, 'user_secret' => '85f0d570-a265-4d9d-857e-b30aa57c4fbe', 'service_type' => 1 );
$V_BODY     = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
$V_EXPECTED = '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d';

/* ===================== SIGNER ===================== */
$signer = new SPX_Request_Signer();

t( $V_EXPECTED === $signer->check_sign( $V_APP_ID, $V_SECRET, $V_TS, $V_RND, $V_BODY ), 'Signer matches the official test vector' );

$signed = $signer->sign( $V_APP_ID, $V_SECRET, $V_PAYLOAD );
t( $V_BODY === $signed['body'], 'Signer encodes the exact byte-for-byte JSON body' );
$recomputed = $signer->check_sign( $V_APP_ID, $V_SECRET, (int) $signed['headers']['timestamp'], (int) $signed['headers']['random-num'], $signed['body'] );
t( $recomputed === $signed['headers']['check-sign'], 'sign() check-sign is consistent with its own timestamp/random-num/body' );

$pretty = json_encode( $V_PAYLOAD, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
t( $signer->check_sign( $V_APP_ID, $V_SECRET, $V_TS, $V_RND, $pretty ) !== $V_EXPECTED, 'Reformatting the JSON body changes the signature' );
t( $signer->check_sign( $V_APP_ID, $V_SECRET, $V_TS + 1, $V_RND, $V_BODY ) !== $V_EXPECTED, 'Changing timestamp changes the signature' );
t( $signer->check_sign( $V_APP_ID, $V_SECRET, $V_TS, $V_RND + 1, $V_BODY ) !== $V_EXPECTED, 'Changing random-num changes the signature' );

$uni = array( 'name' => 'Nguyễn Việt', 'city' => 'TP. Hồ Chí Minh' );
t( $signer->sign( '1', 'k', $uni )['body'] === json_encode( $uni, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'Unicode is left unescaped and stable' );
t( false === strpos( $signer->sign( '1', 'k', array( 'u' => 'a/b/c' ) )['body'], '\\/' ), 'Slashes are left unescaped' );

$threw = false;
try { $signer->sign( '1', 'k', array( 'bad' => "\xB1\x31\xB2" ) ); } catch ( SPX_Signer_Exception $e ) { $threw = true; }
t( $threw, 'json_encode failure raises a controlled SPX_Signer_Exception' );
t( false === strpos( json_encode( $signed['headers'] ), $V_SECRET ), 'Signer output never contains the app-secret' );

/* ===================== CONFIG ===================== */
with_env( array(
	'SPX_TEST_BASE_URL' => 'https://test-stable.spx.vn/', 'SPX_TEST_APP_ID' => '1000490',
	'SPX_TEST_APP_SECRET' => 'shhh-secret-value-xyz', 'SPX_TEST_USER_ID' => '277447632848309',
	'SPX_TEST_USER_SECRET' => 'aaaa-bbbb-cccc-secret',
), function () {
	$c = SPX_API_Config::for_test();
	t( 'https://test-stable.spx.vn/' === $c->get_base_url(), 'Test config base URL is the sandbox host' );
	t( $c->is_valid(), 'Test config with full credentials is valid' );
	t( $c->has_account_credentials(), 'Test config detects account credentials' );
	$dump = json_encode( $c->to_safe_array() ) . '|' . json_encode( $c->__debugInfo() );
	t( false === strpos( $dump, 'shhh-secret-value-xyz' ), 'Safe dump masks the app-secret' );
	t( false === strpos( $dump, 'aaaa-bbbb-cccc-secret' ), 'Safe dump masks the user-secret' );
	t( false === strpos( (string) $c, 'shhh-secret-value-xyz' ), '__toString never leaks the secret' );

	$prod = SPX_API_Config::for_production();
	t( '' === $prod->get_app_id() && ! $prod->is_valid(), 'Production config does not reuse test credentials and is not valid' );
} );

with_env( array( 'SPX_TEST_BASE_URL' => 'https://test-stable.spx.vn/', 'SPX_TEST_APP_ID' => '1000490', 'SPX_TEST_APP_SECRET' => '' ), function () {
	t( ! SPX_API_Config::for_test()->has_required_credentials(), 'Missing app-secret is reported as missing credentials' );
} );

/* ===================== HTTP CLIENT ===================== */
function spx_client_for( $base ) {
	putenv( 'SPX_TEST_BASE_URL=' . $base );
	putenv( 'SPX_TEST_APP_ID=1000490' );
	putenv( 'SPX_TEST_APP_SECRET=secret' );
	putenv( 'SPX_TEST_USER_ID=277447632848309' );
	putenv( 'SPX_TEST_USER_SECRET=uuuu-secret' );
	return new SPX_HTTP_Client( SPX_API_Config::for_test(), new SPX_Request_Signer() );
}

$client = spx_client_for( 'https://test-stable.spx.vn/' );
spx_http_set( spx_http_ok( 0, array( 'match_result' => true ) ) );
$resp = $client->request( 'https://evil.example/open/api/v1/x', array( 'a' => 1 ) );
t( ! $resp->is_success() && 0 === $GLOBALS['spx_http']['calls'], 'Client rejects an absolute URL passed as path (no network call)' );

$resp = $client->request( '/other/path', array( 'a' => 1 ) );
t( ! $resp->is_success() && 0 === $GLOBALS['spx_http']['calls'], 'Client rejects a path outside /open/api/' );

$resp = $client->request( '/open/api/../secret', array( 'a' => 1 ) );
t( ! $resp->is_success() && 0 === $GLOBALS['spx_http']['calls'], 'Client rejects path traversal' );

$resp = $client->request( 'open/api/v1/x', array( 'a' => 1 ) );
t( ! $resp->is_success() && 0 === $GLOBALS['spx_http']['calls'], 'Client rejects a non-absolute (schemeless) path' );

$bad_host = spx_client_for( 'https://test-stable.spx.vn.evil.example/' );
spx_http_set( spx_http_ok( 0 ) );
$resp = $bad_host->request( '/open/api/v1/account/verify', array( 'a' => 1 ) );
t( $resp->is_success() && 'https://test-stable.spx.vn/open/api/v1/account/verify' === $GLOBALS['spx_http']['last_args']['url'], 'Deprecated base URL input cannot replace canonical sandbox host' );

$http_scheme = spx_client_for( 'http://test-stable.spx.vn/' );
spx_http_set( spx_http_ok( 0 ) );
$resp = $http_scheme->request( '/open/api/v1/account/verify', array( 'a' => 1 ) );
t( $resp->is_success() && 1 === $GLOBALS['spx_http']['calls'], 'Deprecated HTTP base input is ignored in favor of canonical HTTPS' );

spx_http_set( spx_http_ok( 0 ) );
$resp = $http_scheme->request( '/open/api/v1/order/unimplemented', array( 'a' => 1 ) );
t( ! $resp->is_success() && 0 === $GLOBALS['spx_http']['calls'], 'Client requires an exact implemented endpoint' );

$client = spx_client_for( 'https://test-stable.spx.vn/' );
spx_http_set( spx_http_ok( 0, array( 'match_result' => true ) ) );
$resp = $client->request( '/open/api/v1/account/verify', array( 'user_id' => 1, 'user_secret' => 'x' ) );
$args = $GLOBALS['spx_http']['last_args'];
t( $resp->is_success() && 1 === $GLOBALS['spx_http']['calls'], 'Client sends a valid request and parses success' );
t( 'https://test-stable.spx.vn/open/api/v1/account/verify' === $args['url'], 'Client builds the absolute URL from base + path' );
t( 20 === $args['timeout'] && true === $args['sslverify'] && 0 === $args['redirection'], 'Client sets timeout=20, TLS on, no redirects' );
t( $args['body'] === json_encode( array( 'user_id' => 1, 'user_secret' => 'x' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'Client sends exactly the signed body string' );
t( isset( $args['headers']['check-sign'] ) && isset( $args['headers']['app-id'] ), 'Client attaches signature headers' );

/* Optional logger receives only a safe, secret-free context. */
putenv( 'SPX_TEST_BASE_URL=https://test-stable.spx.vn/' ); putenv( 'SPX_TEST_APP_ID=1000490' );
putenv( 'SPX_TEST_APP_SECRET=super-secret' ); putenv( 'SPX_TEST_USER_ID=277447632848309' ); putenv( 'SPX_TEST_USER_SECRET=uuuu-secret' );
$captured = array();
$logged_client = new SPX_HTTP_Client( SPX_API_Config::for_test(), new SPX_Request_Signer(), function ( $level, $context ) use ( &$captured ) { $captured[] = array( $level, $context ); } );
spx_http_set( spx_http_ok( 0, array( 'match_result' => true ) ) );
$logged_client->request( '/open/api/v1/account/verify', array( 'user_id' => 277447632848309, 'user_secret' => 'uuuu-secret' ) );
$log_json = json_encode( $captured );
t( count( $captured ) === 1 && 'info' === $captured[0][0], 'Logger is invoked once with a level' );
t( false === strpos( $log_json, 'uuuu-secret' ) && false === strpos( $log_json, 'super-secret' ), 'Logged context contains no secrets' );
t( ! array_key_exists( 'body', $captured[0][1] ) && ! array_key_exists( 'check-sign', $captured[0][1] ), 'Logged context contains no body or check-sign' );

/* ===================== RESPONSE ===================== */
$r = SPX_API_Response::from_wp_error( new WP_Error( 'timeout', 'Operation timed out' ) );
t( ! $r->is_success() && $r->is_retryable() && 'transport' === $r->get_category(), 'WP_Error maps to a retryable transport failure' );

$r = SPX_API_Response::from_http( 500, '' );
t( ! $r->is_success() && $r->is_retryable(), 'HTTP 500 is a retryable failure' );

$r = SPX_API_Response::from_http( 200, 'not-json' );
t( ! $r->is_success() && ! $r->is_retryable(), 'Invalid JSON is a non-retryable failure' );

$r = SPX_API_Response::from_http( 200, '{"message":"x"}' );
t( ! $r->is_success(), 'Missing ret_code is a failure' );

$r = SPX_API_Response::from_http( 200, '{"ret_code":0,"data":{"k":1}}' );
t( $r->is_success() && is_array( $r->get_data() ), 'ret_code 0 is success with data' );

$r = SPX_API_Response::from_http( 200, '{"ret_code":1007,"message":"x"}' );
t( ! $r->is_success() && 'authentication' === $r->get_category() && ! $r->is_retryable(), 'ret_code 1007 is a non-retryable auth error' );

$r = SPX_API_Response::from_http( 200, '{"ret_code":99004}' );
t( ! $r->is_success() && 'temporary' === $r->get_category() && $r->is_retryable(), 'ret_code 99004 is a retryable temporary error' );

t( false === strpos( json_encode( SPX_API_Response::from_http( 200, '{"ret_code":13251,"debug_msg":"secret-internal-detail"}' )->to_log_array() ), 'secret-internal-detail' ), 'Raw debug_msg never appears in the log array' );

/* ===================== ACCOUNT SERVICE ===================== */
function spx_account_service() {
	putenv( 'SPX_TEST_BASE_URL=https://test-stable.spx.vn/' );
	putenv( 'SPX_TEST_APP_ID=1000490' ); putenv( 'SPX_TEST_APP_SECRET=secret' );
	putenv( 'SPX_TEST_USER_ID=277447632848309' ); putenv( 'SPX_TEST_USER_SECRET=uuuu-secret' );
	$config = SPX_API_Config::for_test();
	return new SPX_Account_Service( $config, new SPX_HTTP_Client( $config, new SPX_Request_Signer() ) );
}

spx_http_set( spx_http_ok( 0, array( 'match_result' => true ) ) );
$out = spx_account_service()->verify_credentials();
t( true === $out['success'] && true === $out['verified'], 'Account verify: match_result true → verified' );

spx_http_set( spx_http_ok( 0, array( 'match_result' => false ) ) );
$out = spx_account_service()->verify_credentials();
t( true === $out['success'] && false === $out['verified'], 'Account verify: match_result false → not verified (not an HTTP failure)' );

spx_http_set( spx_http_ok( 0, null ) );
$out = spx_account_service()->verify_credentials();
t( false === $out['verified'], 'Account verify: missing data → not verified' );

spx_http_set( array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'ret_code' => 12051 ) ) ) );
$out = spx_account_service()->verify_credentials();
t( false === $out['success'] && 12051 === $out['ret_code'], 'Account verify: non-zero ret_code → failure with code preserved' );

t( false === strpos( json_encode( $out ), 'uuuu-secret' ), 'Account verify result never contains the user-secret' );

echo "\nAll SPX API foundation tests passed ({$GLOBALS['spx_tests']} assertions).\n";
