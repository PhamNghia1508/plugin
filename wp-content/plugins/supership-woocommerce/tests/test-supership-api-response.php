<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}

require_once dirname( __DIR__ ) . '/includes/api/class-supership-api-response.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

// Success envelope, matching the real format confirmed at
// docs.developers.supership.vn/guide/ Response Format section.
$success_body = json_encode( array(
	'status'  => 'Success',
	'message' => 'OK',
	'results' => array( 'code' => 'SGNS336484LM.883568271' ),
) );
$response = SuperShip_API_Response::from_http( 200, $success_body );
expect_same( true, $response->is_success(), 'HTTP 200 + status=Success must be treated as success' );
expect_same( 'success', $response->get_category(), 'Successful response must categorize as success' );
expect_same( 'SGNS336484LM.883568271', $response->get_results()['code'], 'Successful response must expose parsed results' );
expect_same( false, $response->is_retryable(), 'A successful response must not be marked retryable' );

// Error envelope.
$error_body = json_encode( array(
	'status'  => 'Error',
	'message' => 'Địa chỉ không hợp lệ',
	'errors'  => array( 'province' => 'not found' ),
) );
$error_response = SuperShip_API_Response::from_http( 422, $error_body );
expect_same( false, $error_response->is_success(), 'status=Error must never be treated as success, regardless of field content' );
expect_same( 'client_error', $error_response->get_category(), '4xx must categorize as client_error' );
expect_same( false, $error_response->is_retryable(), '422 is not one of the retryable codes (5xx/429/network)' );
expect_same( 'Địa chỉ không hợp lệ', $error_response->get_message(), 'Error message must be exposed as-is for the error mapper to sanitize' );

// Server errors and network errors must be retryable; client errors must not.
expect_same( true, SuperShip_API_Response::from_http( 500, $error_body )->is_retryable(), '5xx must be retryable' );
expect_same( true, SuperShip_API_Response::from_http( 429, $error_body )->is_retryable(), '429 (rate limit) must be retryable' );
expect_same( false, SuperShip_API_Response::from_http( 400, $error_body )->is_retryable(), '400 must not be retryable' );

$wp_error_response = SuperShip_API_Response::from_wp_error( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );
expect_same( false, $wp_error_response->is_success(), 'A WP_Error (network failure) must never be success' );
expect_same( true, $wp_error_response->is_retryable(), 'A WP_Error (network failure) must be retryable' );
expect_same( 'network_error', $wp_error_response->get_category(), 'A WP_Error must categorize as network_error' );

// Malformed JSON must not throw and must be treated as a (non-retryable-by-default) error.
$malformed = SuperShip_API_Response::from_http( 200, 'this is not json' );
expect_same( false, $malformed->is_success(), 'Malformed JSON body must not be treated as success even with HTTP 200' );

// config_error() (used when our own HTTP client refuses to build a request - e.g. missing
// token or a URL outside the whitelisted host) must also never be "success".
$config_error = SuperShip_API_Response::config_error( 'missing_token', 'SuperShip access token is not available.' );
expect_same( false, $config_error->is_success(), 'config_error() must never report success' );
expect_same( 0, $config_error->get_http_status(), 'config_error() has no real HTTP status - must be 0' );

echo "All SuperShip_API_Response tests passed.\n";
