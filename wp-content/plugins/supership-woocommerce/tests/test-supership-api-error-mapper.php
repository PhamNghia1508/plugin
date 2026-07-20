<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }

require_once dirname( __DIR__ ) . '/includes/api/class-supership-api-response.php';
require_once dirname( __DIR__ ) . '/includes/api/class-supership-api-error-mapper.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

function make_response( int $http_status, string $status, string $message, $errors = null ) {
	$body = json_encode( array( 'status' => $status, 'message' => $message, 'errors' => $errors ) );
	return SuperShip_API_Response::from_http( $http_status, $body );
}

// SuperShip already returns Vietnamese human-readable messages - the mapper should pass
// them through (sanitized), not replace them with a generic fallback.
$response = make_response( 422, 'Error', 'Địa chỉ Phường/Xã không hợp lệ' );
expect_same(
	'Địa chỉ Phường/Xã không hợp lệ',
	SuperShip_API_Error_Mapper::get_safe_message( $response ),
	'get_safe_message() must pass through SuperShip\'s own message when present'
);

// A successful response has no error message.
$success = make_response( 200, 'Success', '' );
expect_same( '', SuperShip_API_Error_Mapper::get_safe_message( $success ), 'Successful responses must not produce an error message' );

// 401/403 must be detected as auth errors regardless of message wording.
$auth_error = make_response( 401, 'Error', 'Some vague message' );
expect_same( true, SuperShip_API_Error_Mapper::is_auth_error( $auth_error ), 'HTTP 401 must always be classified as an auth error' );
expect_same( 'check_credentials', SuperShip_API_Error_Mapper::get_recommended_action( $auth_error ), '401 should recommend checking credentials' );

// Keyword-based auth detection (e.g. token expired mid-flow, still HTTP 200 wrapper).
$token_error = make_response( 400, 'Error', 'Token đã hết hạn, vui lòng đăng nhập lại' );
expect_same( true, SuperShip_API_Error_Mapper::is_auth_error( $token_error ), 'Vietnamese "Token" keyword must be detected as an auth error even outside 401/403' );

// Validation errors (SuperShip's "errors" field populated) must be detected and normalized.
$validation_error = make_response( 422, 'Error', 'Dữ liệu không hợp lệ', array( 'commune' => 'Phường/Xã không tồn tại' ) );
expect_same( true, SuperShip_API_Error_Mapper::is_validation_error( $validation_error ), 'A populated "errors" field must be detected as a validation error' );
$errors = SuperShip_API_Error_Mapper::get_validation_errors( $validation_error );
expect_same( 'Phường/Xã không tồn tại', $errors['commune'], 'get_validation_errors() must expose the per-field message' );
expect_same( 'fix_data', SuperShip_API_Error_Mapper::get_recommended_action( $validation_error ), 'Validation errors should recommend fixing the submitted data' );

// Retryable errors (5xx) should recommend retry, above any other classification.
$server_error = make_response( 500, 'Error', 'Internal error' );
expect_same( 'retry', SuperShip_API_Error_Mapper::get_recommended_action( $server_error ), '5xx must recommend retry first' );

// Sensitive data (phone numbers, long tokens) must be redacted from messages we display/log.
$leaky = make_response( 400, 'Error', 'Không thể liên hệ số điện thoại 0987654321 với token abcd1234efgh5678ijkl9012mnop3456' );
$safe  = SuperShip_API_Error_Mapper::get_safe_message( $leaky );
expect_same( false, false !== strpos( $safe, '0987654321' ), 'Full phone numbers must be redacted from error messages' );
expect_same( false, false !== strpos( $safe, 'abcd1234efgh5678ijkl9012mnop3456' ), 'Long token-like strings must be redacted from error messages' );

echo "All SuperShip_API_Error_Mapper tests passed.\n";
