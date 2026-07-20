<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Authentication Manager
 * 
 * Central orchestrator for SuperShip authentication:
 * - Personal Access Token mode: Returns static token
 * - Password Grant mode: Login → store token → auto-refresh when needed
 * 
 * Usage:
 *   $manager = new SuperShip_Auth_Manager();
 *   $token = $manager->get_token(); // Auto-refreshes if needed
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Auth_Manager {
	
	const LOGIN_ENDPOINT = '/v1/partner/auth/login';
	
	/** @var SuperShip_Auth_Config */
	private $config;
	
	/** @var SuperShip_Token_Store */
	private $token_store;

	/**
	 * @param SuperShip_Auth_Config|null $config Optional config (defaults to from_options)
	 * @param SuperShip_Token_Store|null $token_store Optional token store
	 */
	public function __construct( SuperShip_Auth_Config $config = null, SuperShip_Token_Store $token_store = null ) {
		$this->config      = $config ?: SuperShip_Auth_Config::from_options();
		$this->token_store = $token_store ?: new SuperShip_Token_Store();
	}

	/**
	 * Get valid access token (auto-refresh if needed)
	 * 
	 * @return string Access token or empty on failure
	 */
	public function get_token(): string {
		// Personal Token mode: static token, no refresh
		if ( $this->config->is_personal_token_mode() ) {
			return $this->config->get_personal_token();
		}
		
		// Password Grant mode: check expiry, refresh if needed
		if ( ! $this->token_store->is_expired() ) {
			$token = $this->token_store->get();
			return $token ? $token['access_token'] : '';
		}
		
		// Token expired or missing: login to get new token
		$result = $this->login();
		
		if ( ! $result['success'] ) {
			return ''; // Login failed
		}
		
		return $result['access_token'];
	}

	/**
	 * Perform Password Grant login
	 * 
	 * POST /v1/partner/auth/login
	 * {
	 *   "client_id": "...",
	 *   "client_secret": "...",
	 *   "username": "email@example.com",
	 *   "password": "...",
	 *   "partner": "..."
	 * }
	 * 
	 * Response:
	 * {
	 *   "status": "Success",
	 *   "results": {
	 *     "token_type": "Bearer",
	 *     "expires_in": 31536000,
	 *     "access_token": "..."
	 *   }
	 * }
	 * 
	 * @return array{success:bool,access_token?:string,message?:string}
	 */
	public function login(): array {
		if ( ! $this->config->has_credentials() ) {
			return array(
				'success' => false,
				'message' => __( 'SuperShip credentials are not configured.', 'supership-woocommerce' ),
			);
		}
		
		$url     = $this->config->get_base_url() . self::LOGIN_ENDPOINT;
		$payload = array(
			'client_id'     => $this->config->get_client_id(),
			'client_secret' => $this->config->get_client_secret(),
			'username'      => $this->config->get_username(),
			'password'      => $this->config->get_password(),
			'partner'       => $this->config->get_partner_code(),
		);
		
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
				'redirection' => 0,
				'sslverify'   => true,
			)
		);
		
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'SuperShip login failed: %s', 'supership-woocommerce' ),
					$response->get_error_message()
				),
			);
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );
		
		if ( 200 !== $status_code || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'SuperShip login failed with status %d', 'supership-woocommerce' ),
					$status_code
				),
			);
		}
		
		// Check SuperShip response format
		if ( 'Success' !== ( $data['status'] ?? '' ) ) {
			$error_msg = $data['message'] ?? __( 'Unknown error', 'supership-woocommerce' );
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'SuperShip login error: %s', 'supership-woocommerce' ),
					$error_msg
				),
			);
		}
		
		$results = $data['results'] ?? array();
		if ( empty( $results['access_token'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'SuperShip did not return an access token.', 'supership-woocommerce' ),
			);
		}
		
		$access_token = $results['access_token'];
		$token_type   = $results['token_type'] ?? 'Bearer';
		$expires_in   = isset( $results['expires_in'] ) ? (int) $results['expires_in'] : 31536000;
		
		// Store token
		$saved = $this->token_store->save( $access_token, $token_type, $expires_in );
		
		if ( ! $saved ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to save SuperShip access token.', 'supership-woocommerce' ),
			);
		}
		
		return array(
			'success'      => true,
			'access_token' => $access_token,
			'token_type'   => $token_type,
			'expires_in'   => $expires_in,
		);
	}

	/**
	 * Force refresh token (re-login)
	 *
	 * @return array Login result
	 */
	public function refresh(): array {
		$this->token_store->clear();
		return $this->login();
	}

	/**
	 * Register a brand-new SuperShip account.
	 *
	 * POST /v1/partner/auth/register
	 *
	 * IMPORTANT: per docs.developers.supership.vn/guide/users.html, `partner`
	 * (Mã Bí Mật) is REQUIRED for this endpoint, unlike order/warehouse
	 * creation where it's optional. This means self-service registration
	 * only works for merchants who already have a Partner Code issued by
	 * SuperShip (large e-commerce/software partners) - a regular single-shop
	 * merchant should instead create an account directly on
	 * https://khachhang.supership.vn and paste their Personal Access Token.
	 *
	 * On success, the returned access_token is auto-saved as a Personal
	 * Access Token so the new account is immediately usable.
	 *
	 * @param array $params {project, name, phone, email, password, partner}
	 * @return array {success:bool, code?:string, referral?:string, message?:string}
	 */
	public static function register( array $params ): array {
		$required = array( 'project', 'name', 'phone', 'email', 'password', 'partner' );
		foreach ( $required as $field ) {
			if ( empty( $params[ $field ] ) ) {
				return array(
					'success' => false,
					'message' => __( 'Vui lòng điền đầy đủ thông tin, bao gồm cả Mã Bí Mật (Partner Code) - trường này là bắt buộc để đăng ký qua API.', 'supership-woocommerce' ),
				);
			}
		}

		$response = wp_remote_post(
			SuperShip_Auth_Config::BASE_URL . '/v1/partner/auth/register',
			array(
				'timeout'     => 20,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'project'  => $params['project'],
						'name'     => $params['name'],
						'phone'    => $params['phone'],
						'email'    => $params['email'],
						'password' => $params['password'],
						'partner'  => $params['partner'],
					)
				),
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'Đăng ký thất bại: %s', 'supership-woocommerce' ),
					$response->get_error_message()
				),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || ! is_array( $data ) || 'Success' !== ( $data['status'] ?? '' ) ) {
			$message = ( is_array( $data ) && ! empty( $data['message'] ) )
				? $data['message']
				: sprintf( __( 'Đăng ký thất bại (HTTP %d)', 'supership-woocommerce' ), $status_code );

			return array(
				'success' => false,
				'message' => $message,
			);
		}

		$results = $data['results'] ?? array();

		if ( empty( $results['access_token'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'SuperShip không trả về access token.', 'supership-woocommerce' ),
			);
		}

		// Auto-provision as Personal Access Token so the new account is immediately usable.
		$storage   = new SuperShip_Secret_Storage();
		$encrypted = $storage->encrypt( $results['access_token'] );

		update_option( 'supership_auth_mode', SuperShip_Auth_Config::MODE_PERSONAL_TOKEN );
		if ( '' !== $encrypted ) {
			update_option( 'supership_personal_token', $encrypted );
		}

		return array(
			'success'  => true,
			'code'     => $results['code'] ?? '',
			'referral' => $results['referral'] ?? '',
		);
	}

	/**
	 * Clear stored token
	 * 
	 * @return bool Success
	 */
	public function logout(): bool {
		return $this->token_store->clear();
	}

	/**
	 * Check if authentication is ready (has valid token or personal token)
	 * 
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return '' !== $this->get_token();
	}

	/**
	 * Get authentication status info
	 * 
	 * @return array Status array (safe, no plaintext secrets)
	 */
	public function get_status(): array {
		$status = array(
			'mode'            => $this->config->get_mode(),
			'has_credentials' => $this->config->has_credentials(),
			'authenticated'   => false,
		);
		
		if ( $this->config->is_personal_token_mode() ) {
			$status['authenticated'] = '' !== $this->config->get_personal_token();
			$status['token_info']    = array(
				'type'   => 'personal',
				'exists' => $status['authenticated'],
			);
		} else {
			$status['authenticated'] = $this->is_authenticated();
			$status['token_info']    = $this->token_store->to_safe_array();
		}
		
		return $status;
	}

	/**
	 * Migration helper - called on plugin activation
	 * Migrate from SPX credentials if needed (placeholder for now)
	 */
	public static function maybe_migrate() {
		// TODO: If migrating from SPX, clear old credentials and prompt user
		// For now, this is a no-op since we're starting fresh
		
		// Ensure token store table/option exists (WordPress options are auto-created)
		// No action needed
	}
}
