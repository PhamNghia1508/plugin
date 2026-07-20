<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip API Error Mapper
 * 
 * Maps SuperShip error messages to user-friendly Vietnamese messages.
 * 
 * Note: SuperShip returns human-readable messages in Vietnamese already,
 * but this mapper provides fallbacks and categorization.
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_API_Error_Mapper {
	
	/**
	 * Get user-friendly error message
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return string Localized error message
	 */
	public static function get_safe_message( SuperShip_API_Response $response ): string {
		if ( $response->is_success() ) {
			return '';
		}
		
		$message = $response->get_message();
		
		// SuperShip already provides Vietnamese messages
		// Just sanitize and return
		if ( '' !== $message ) {
			return self::sanitize_message( $message );
		}
		
		// Fallback to HTTP status-based messages
		return $response->get_error_message();
	}

	/**
	 * Check if error is related to authentication
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return bool
	 */
	public static function is_auth_error( SuperShip_API_Response $response ): bool {
		$http_status = $response->get_http_status();
		
		if ( 401 === $http_status || 403 === $http_status ) {
			return true;
		}
		
		$message = strtolower( $response->get_message() );
		
		$auth_keywords = array(
			'token',
			'unauthorized',
			'authentication',
			'xác thực',
			'đăng nhập',
		);
		
		foreach ( $auth_keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Check if error is related to validation
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return bool
	 */
	public static function is_validation_error( SuperShip_API_Response $response ): bool {
		$http_status = $response->get_http_status();
		
		if ( 400 === $http_status || 422 === $http_status ) {
			return true;
		}
		
		$errors = $response->get_errors();
		
		// SuperShip returns validation errors in "errors" field
		return is_array( $errors ) && ! empty( $errors );
	}

	/**
	 * Get validation error details
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return array Array of field => error message
	 */
	public static function get_validation_errors( SuperShip_API_Response $response ): array {
		if ( ! self::is_validation_error( $response ) ) {
			return array();
		}
		
		$errors = $response->get_errors();
		
		if ( ! is_array( $errors ) ) {
			return array();
		}
		
		// SuperShip errors format may vary - normalize it
		$normalized = array();
		
		foreach ( $errors as $key => $value ) {
			if ( is_string( $value ) ) {
				$normalized[ $key ] = self::sanitize_message( $value );
			} elseif ( is_array( $value ) ) {
				$normalized[ $key ] = implode( ', ', array_map( 'self::sanitize_message', $value ) );
			}
		}
		
		return $normalized;
	}

	/**
	 * Sanitize error message (remove sensitive data, limit length)
	 * 
	 * @param string $message Raw message
	 * @return string Sanitized message
	 */
	private static function sanitize_message( string $message ): string {
		$message = trim( strip_tags( $message ) );
		
		// Remove potential sensitive patterns (phone numbers, tokens)
		$message = preg_replace( '/\b\d{10,11}\b/', '[REDACTED]', $message );
		$message = preg_replace( '/\b[A-Za-z0-9]{32,}\b/', '[TOKEN]', $message );
		
		// Limit length
		if ( mb_strlen( $message ) > 500 ) {
			$message = mb_substr( $message, 0, 497 ) . '...';
		}
		
		return sanitize_text_field( $message );
	}

	/**
	 * Get recommended action for error
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return string Recommended action (retry, check_credentials, contact_support, fix_data)
	 */
	public static function get_recommended_action( SuperShip_API_Response $response ): string {
		if ( $response->is_retryable() ) {
			return 'retry';
		}
		
		if ( self::is_auth_error( $response ) ) {
			return 'check_credentials';
		}
		
		if ( self::is_validation_error( $response ) ) {
			return 'fix_data';
		}
		
		$http_status = $response->get_http_status();
		
		if ( $http_status >= 500 ) {
			return 'contact_support';
		}
		
		return 'review_request';
	}
}
