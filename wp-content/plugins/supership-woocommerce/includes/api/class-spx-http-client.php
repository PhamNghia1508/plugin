<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sends signed POST JSON requests to SPX through the WordPress HTTP API.
 * Strictly whitelists scheme/host/port and the /open/api/ path prefix; never
 * follows redirects, never auto-retries, and never logs bodies or headers.
 */
final class SPX_HTTP_Client implements SPX_Http_Client_Interface {
	const TIMEOUT           = 20;
	const ALLOWED_TEST_HOST = 'test-stable.spx.vn';
	const ALLOWED_PRODUCTION_HOST = 'spx.vn';

	/** @var SPX_API_Config */    private $config;
	/** @var SPX_Request_Signer */ private $signer;
	/** @var callable|null */      private $logger;

	/**
	 * @param callable|null $logger Optional. Receives ( string $level, array $context )
	 *                              where $context is SPX_API_Response::to_log_array()
	 *                              (already free of body/headers/secrets/raw debug_msg).
	 */
	public function __construct( SPX_API_Config $config, SPX_Request_Signer $signer, ?callable $logger = null ) {
		$this->config = $config;
		$this->signer = $signer;
		$this->logger = $logger;
	}

	public function request( string $path, array $payload ): SPX_API_Response {
		$result = $this->dispatch( $path, $payload );
		if ( null !== $this->logger ) {
			call_user_func( $this->logger, $result->is_success() ? 'info' : 'warning', $result->to_log_array() );
		}
		return $result;
	}

	private function dispatch( string $path, array $payload ): SPX_API_Response {
		$url = $this->build_url( $path );
		if ( null === $url ) {
			return SPX_API_Response::config_error( 'invalid_endpoint', __( 'The SPX endpoint path or host is not allowed.', 'spx-express-woocommerce' ) );
		}
		if ( ! $this->config->has_required_credentials() ) {
			return SPX_API_Response::config_error( 'missing_credentials', __( 'SPX API credentials are not configured.', 'spx-express-woocommerce' ) );
		}
		if ( SPX_API_Config::PRODUCTION_ENV === $this->config->get_environment() ) {
			// Operation-aware Production enablement gate. account_verify may run
			// under the bootstrap policy (admin HTTPS request); every other
			// operation requires a valid verification marker. Transport security
			// (host/HTTPS/port/sslverify/redirects) is still enforced below and
			// in build_url() regardless of this decision.
			$operation = SPX_Production_Gate::operation_for_path( $path );
			$decision  = SPX_Production_Gate::evaluate( $operation, SPX_Production_Gate::runtime_context() );
			if ( empty( $decision['allowed'] ) ) {
				return SPX_API_Response::config_error( 'production_' . sanitize_key( (string) $decision['reason'] ), __( 'Thao tác Production đang bị khóa. Vui lòng xác minh kết nối SPX.', 'spx-express-woocommerce' ) );
			}
		}

		try {
			$signed = $this->signer->sign( $this->config->get_app_id(), $this->config->get_app_secret(), $payload );
		} catch ( SPX_Signer_Exception $e ) {
			return SPX_API_Response::config_error( 'signing_failed', __( 'The SPX request could not be signed.', 'spx-express-woocommerce' ) );
		}

		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'httpversion' => '1.1',
				'headers'     => $signed['headers'],
				'body'        => $signed['body'],
			)
		);
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$result = SPX_API_Response::from_wp_error( $response );
		} else {
			$result = SPX_API_Response::from_http(
				(int) wp_remote_retrieve_response_code( $response ),
				(string) wp_remote_retrieve_body( $response )
			);
		}
		$result->set_context( $path, $duration, $this->config->get_environment() );
		return $result;
	}

	/**
	 * Build and strictly validate the absolute URL from a relative SPX path.
	 * Returns null (caller turns into an error) if anything is off.
	 */
	private function build_url( string $path ): ?string {
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) { return null; } // must be root-relative
		if ( '/' === substr( $path, 0, 2 ) && '//' === substr( $path, 0, 2 ) ) { return null; } // protocol-relative
		if ( 0 === strpos( $path, '//' ) ) { return null; }
		if ( false !== strpos( $path, '://' ) ) { return null; }               // absolute URL
		if ( false !== strpos( $path, '..' ) ) { return null; }                // traversal
		if ( ! SPX_Environment::is_allowed_endpoint( $path ) ) { return null; }

		$url   = rtrim( $this->config->get_base_url(), '/' ) . $path;
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) { return null; }
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) { return null; }
		if ( empty( $parts['host'] ) ) { return null; }
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return null; } // no userinfo
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) { return null; }

		$expected_host = SPX_Environment::host( $this->config->get_environment() );
		if ( '' === $expected_host || $expected_host !== strtolower( $parts['host'] ) ) { return null; }
		return $url;
	}
}
