<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip HTTP Client
 * 
 * Handles authenticated HTTP requests to SuperShip API with Bearer token.
 * 
 * Features:
 * - Bearer token authentication (via SuperShip_Auth_Manager)
 * - Strict URL validation (HTTPS only, whitelisted host)
 * - No auto-retry (caller decides retry logic)
 * - Safe logging (no request/response bodies)
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_HTTP_Client implements SuperShip_Http_Client_Interface {
	
	const TIMEOUT        = 20;
	const ALLOWED_HOST   = 'api.mysupership.vn';
	const BASE_URL       = 'https://api.mysupership.vn';
	
	/** @var SuperShip_Auth_Manager */
	private $auth_manager;
	
	/** @var callable|null */
	private $logger;

	/**
	 * @param SuperShip_Auth_Manager|null $auth_manager Auth manager (defaults to new instance)
	 * @param callable|null|false         $logger Logger callback( string $level, array $context ). Defaults to
	 *                                    WC_Logger (visible under WooCommerce > Status > Logs, source
	 *                                    "supership-api"). Pass `false` explicitly to disable logging.
	 */
	public function __construct( SuperShip_Auth_Manager $auth_manager = null, $logger = null ) {
		$this->auth_manager = $auth_manager ?: new SuperShip_Auth_Manager();

		if ( false === $logger ) {
			$this->logger = null;
		} else {
			$this->logger = $logger ?: array( __CLASS__, 'default_logger' );
		}
	}

	/**
	 * Default logger: writes to WC_Logger so API traffic (rate lookups,
	 * shipment creation, cancellations, label/warehouse/webhook calls) is
	 * visible under WooCommerce > Status > Logs even without WP_DEBUG.
	 * No request/response bodies are logged - only the safe summary from
	 * SuperShip_API_Response::to_log_array().
	 *
	 * Failed calls ('warning') are always logged. Successful calls ('info')
	 * are only logged when "Debug Mode" is enabled in Advanced settings, to
	 * avoid flooding the log on a busy store.
	 *
	 * @param string $level   info|warning (see log_request()).
	 * @param array  $context method, path, and the response's to_log_array().
	 */
	private static function default_logger( string $level, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( 'info' === $level && 'yes' !== get_option( 'supership_debug_mode', 'no' ) ) {
			return;
		}

		$message = sprintf(
			'%s %s -> HTTP %s (%s)%s',
			$context['method'] ?? '',
			$context['path'] ?? '',
			$context['http_status'] ?? '?',
			$context['status'] ?? '?',
			! empty( $context['message'] ) ? ': ' . $context['message'] : ''
		);

		wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'supership-api' ), $context ) );
	}

	/**
	 * Make authenticated GET request
	 * 
	 * @param string $path API endpoint path (e.g., '/v1/partner/orders/info')
	 * @param array  $query_params Query parameters
	 * @return SuperShip_API_Response
	 */
	public function get( string $path, array $query_params = array() ): SuperShip_API_Response {
		$url = $this->build_url( $path, $query_params );
		
		if ( null === $url ) {
			return SuperShip_API_Response::config_error(
				'invalid_endpoint',
				__( 'The SuperShip endpoint path or host is not allowed.', 'supership-woocommerce' )
			);
		}
		
		$token = $this->auth_manager->get_token();
		if ( '' === $token ) {
			return SuperShip_API_Response::config_error(
				'missing_token',
				__( 'SuperShip access token is not available.', 'supership-woocommerce' )
			);
		}
		
		$started = microtime( true );
		
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'httpversion' => '1.1',
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);
		
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
		
		$result = $this->parse_response( $response, $path, $duration );
		$this->log_request( 'GET', $path, $result );
		
		return $result;
	}

	/**
	 * Make authenticated POST request
	 * 
	 * @param string $path API endpoint path
	 * @param array  $body Request body (will be JSON-encoded)
	 * @return SuperShip_API_Response
	 */
	public function post( string $path, array $body = array() ): SuperShip_API_Response {
		$url = $this->build_url( $path );
		
		if ( null === $url ) {
			return SuperShip_API_Response::config_error(
				'invalid_endpoint',
				__( 'The SuperShip endpoint path or host is not allowed.', 'supership-woocommerce' )
			);
		}
		
		$token = $this->auth_manager->get_token();
		if ( '' === $token ) {
			return SuperShip_API_Response::config_error(
				'missing_token',
				__( 'SuperShip access token is not available.', 'supership-woocommerce' )
			);
		}
		
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return SuperShip_API_Response::config_error(
				'json_encode_failed',
				__( 'Failed to encode request body as JSON.', 'supership-woocommerce' )
			);
		}
		
		$started = microtime( true );
		
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'httpversion' => '1.1',
				'headers'     => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'        => $json,
			)
		);
		
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
		
		$result = $this->parse_response( $response, $path, $duration );
		$this->log_request( 'POST', $path, $result );
		
		return $result;
	}

	/**
	 * Parse wp_remote response
	 * 
	 * @param array|WP_Error $response WordPress HTTP response
	 * @param string         $path Endpoint path
	 * @param int            $duration_ms Request duration
	 * @return SuperShip_API_Response
	 */
	private function parse_response( $response, string $path, int $duration_ms ): SuperShip_API_Response {
		if ( is_wp_error( $response ) ) {
			$result = SuperShip_API_Response::from_wp_error( $response );
		} else {
			$http_status = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$result      = SuperShip_API_Response::from_http( $http_status, $body );
		}
		
		$result->set_context( $path, $duration_ms );
		return $result;
	}

	/**
	 * Build and validate absolute URL
	 * 
	 * @param string $path Endpoint path (must start with /)
	 * @param array  $query_params Optional query parameters
	 * @return string|null URL or null if invalid
	 */
	private function build_url( string $path, array $query_params = array() ): ?string {
		// Path must be root-relative
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
			return null;
		}
		
		// No protocol-relative or absolute URLs
		if ( 0 === strpos( $path, '//' ) || false !== strpos( $path, '://' ) ) {
			return null;
		}
		
		// No path traversal
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}
		
		// Whitelist: must be under /v1/partner/ or specific endpoints
		$allowed_prefixes = array(
			'/v1/partner/',
		);
		
		$allowed = false;
		foreach ( $allowed_prefixes as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				$allowed = true;
				break;
			}
		}
		
		if ( ! $allowed ) {
			return null;
		}
		
		// Build URL
		$url = self::BASE_URL . $path;
		
		// Add query parameters
		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}
		
		// Final validation
		$parts = wp_parse_url( $url );
		
		if ( ! is_array( $parts ) ) {
			return null;
		}
		
		// Must be HTTPS
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return null;
		}
		
		// Must be our allowed host
		if ( empty( $parts['host'] ) || self::ALLOWED_HOST !== strtolower( $parts['host'] ) ) {
			return null;
		}
		
		// No custom port
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return null;
		}
		
		// No userinfo
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		
		return $url;
	}

	/**
	 * Log request (if logger provided)
	 * 
	 * @param string                  $method HTTP method
	 * @param string                  $path Endpoint path
	 * @param SuperShip_API_Response $response Response object
	 */
	private function log_request( string $method, string $path, SuperShip_API_Response $response ): void {
		if ( null === $this->logger ) {
			return;
		}
		
		$level = $response->is_success() ? 'info' : 'warning';
		
		$context = array_merge(
			array(
				'method' => $method,
				'path'   => $path,
			),
			$response->to_log_array()
		);
		
		call_user_func( $this->logger, $level, $context );
	}
}
