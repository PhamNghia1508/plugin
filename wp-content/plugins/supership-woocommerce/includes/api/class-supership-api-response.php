<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip API Response
 * 
 * Parses and validates SuperShip API responses.
 * 
 * SuperShip response format:
 * - Success: {"status": "Success", "message": "...", "results": {...}}
 * - Error:   {"status": "Error", "message": "...", "errors": {...}}
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_API_Response {
	
	/** @var int */
	private $http_status;
	
	/** @var string */
	private $status;
	
	/** @var string */
	private $message;
	
	/** @var mixed */
	private $results;
	
	/** @var mixed */
	private $errors;
	
	/** @var string */
	private $raw_body;
	
	/** @var string */
	private $endpoint;
	
	/** @var int */
	private $duration_ms;
	
	/** @var string */
	private $category;

	/**
	 * Private constructor - use factory methods
	 */
	private function __construct(
		int $http_status,
		string $status,
		string $message,
		$results,
		$errors,
		string $raw_body
	) {
		$this->http_status = $http_status;
		$this->status      = $status;
		$this->message     = $message;
		$this->results     = $results;
		$this->errors      = $errors;
		$this->raw_body    = $raw_body;
		$this->endpoint    = '';
		$this->duration_ms = 0;
		$this->category    = $this->categorize();
	}

	/**
	 * Create response from HTTP response
	 * 
	 * @param int    $http_status HTTP status code
	 * @param string $body Response body (JSON string)
	 * @return SuperShip_API_Response
	 */
	public static function from_http( int $http_status, string $body ): SuperShip_API_Response {
		$data = json_decode( $body, true );
		
		if ( ! is_array( $data ) ) {
			return new self(
				$http_status,
				'Error',
				__( 'Invalid JSON response from SuperShip', 'supership-woocommerce' ),
				null,
				array( 'json_error' => json_last_error_msg() ),
				$body
			);
		}
		
		$status  = $data['status'] ?? 'Unknown';
		$message = $data['message'] ?? '';
		$results = $data['results'] ?? null;
		$errors  = $data['errors'] ?? null;
		
		return new self( $http_status, $status, $message, $results, $errors, $body );
	}

	/**
	 * Create response from WP_Error
	 * 
	 * @param WP_Error $error WordPress HTTP error
	 * @return SuperShip_API_Response
	 */
	public static function from_wp_error( WP_Error $error ): SuperShip_API_Response {
		return new self(
			0,
			'Error',
			sprintf(
				__( 'HTTP request failed: %s', 'supership-woocommerce' ),
				$error->get_error_message()
			),
			null,
			array( 'wp_error' => $error->get_error_code() ),
			''
		);
	}

	/**
	 * Create error response for configuration issues
	 * 
	 * @param string $code Error code
	 * @param string $message Error message
	 * @return SuperShip_API_Response
	 */
	public static function config_error( string $code, string $message ): SuperShip_API_Response {
		return new self(
			0,
			'Error',
			$message,
			null,
			array( 'config_error' => $code ),
			''
		);
	}

	/**
	 * Set request context (for logging)
	 * 
	 * @param string $endpoint API endpoint
	 * @param int    $duration_ms Request duration in milliseconds
	 */
	public function set_context( string $endpoint, int $duration_ms ): void {
		$this->endpoint    = $endpoint;
		$this->duration_ms = $duration_ms;
	}

	// Getters
	
	public function get_http_status(): int {
		return $this->http_status;
	}
	
	public function get_status(): string {
		return $this->status;
	}
	
	public function get_message(): string {
		return $this->message;
	}
	
	public function get_results() {
		return $this->results;
	}
	
	public function get_errors() {
		return $this->errors;
	}
	
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * Check if response indicates success
	 * 
	 * @return bool
	 */
	public function is_success(): bool {
		return 'Success' === $this->status && $this->http_status >= 200 && $this->http_status < 300;
	}

	/**
	 * Check if error is retryable (network/timeout/5xx)
	 * 
	 * @return bool
	 */
	public function is_retryable(): bool {
		// Network errors (WP_Error)
		if ( 0 === $this->http_status ) {
			return true;
		}
		
		// 5xx server errors
		if ( $this->http_status >= 500 && $this->http_status < 600 ) {
			return true;
		}
		
		// 429 Too Many Requests
		if ( 429 === $this->http_status ) {
			return true;
		}
		
		return false;
	}

	/**
	 * Categorize response type
	 * 
	 * @return string Category: success, client_error, server_error, network_error
	 */
	private function categorize(): string {
		if ( $this->is_success() ) {
			return 'success';
		}
		
		if ( 0 === $this->http_status ) {
			return 'network_error';
		}
		
		if ( $this->http_status >= 500 ) {
			return 'server_error';
		}
		
		if ( $this->http_status >= 400 && $this->http_status < 500 ) {
			return 'client_error';
		}
		
		return 'unknown_error';
	}

	/**
	 * Get safe array for logging (no sensitive data, no large bodies)
	 * 
	 * @return array
	 */
	public function to_log_array(): array {
		$log = array(
			'endpoint'     => $this->endpoint,
			'http_status'  => $this->http_status,
			'status'       => $this->status,
			'message'      => $this->message,
			'category'     => $this->category,
			'duration_ms'  => $this->duration_ms,
			'retryable'    => $this->is_retryable(),
		);
		
		// Include error details (but not full results to avoid log bloat)
		if ( ! $this->is_success() && null !== $this->errors ) {
			$log['errors'] = $this->errors;
		}
		
		return $log;
	}

	/**
	 * Get human-readable error message
	 * 
	 * @return string
	 */
	public function get_error_message(): string {
		if ( $this->is_success() ) {
			return '';
		}
		
		// Use SuperShip message if available
		if ( '' !== $this->message ) {
			return $this->message;
		}
		
		// Fallback based on HTTP status
		if ( 0 === $this->http_status ) {
			return __( 'Network error: Could not connect to SuperShip', 'supership-woocommerce' );
		}
		
		if ( 401 === $this->http_status ) {
			return __( 'Authentication failed: Invalid or expired token', 'supership-woocommerce' );
		}
		
		if ( 403 === $this->http_status ) {
			return __( 'Access denied: Insufficient permissions', 'supership-woocommerce' );
		}
		
		if ( 404 === $this->http_status ) {
			return __( 'Not found: Resource does not exist', 'supership-woocommerce' );
		}
		
		if ( 429 === $this->http_status ) {
			return __( 'Rate limit exceeded: Too many requests', 'supership-woocommerce' );
		}
		
		if ( $this->http_status >= 500 ) {
			return __( 'SuperShip server error: Please try again later', 'supership-woocommerce' );
		}
		
		return sprintf(
			__( 'SuperShip API error (HTTP %d)', 'supership-woocommerce' ),
			$this->http_status
		);
	}

	public function __toString(): string {
		return sprintf(
			'SuperShip_API_Response(status=%s, http=%d, category=%s)',
			$this->status,
			$this->http_status,
			$this->category
		);
	}
}
