<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Authentication Configuration
 * 
 * Manages SuperShip API authentication settings with support for two modes:
 * 1. Personal Access Token (static, simple - for single shop owners)
 * 2. Password Grant OAuth (dynamic, refreshable - for multi-tenant platforms)
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Auth_Config {
	
	const MODE_PERSONAL_TOKEN = 'personal_token';
	const MODE_PASSWORD_GRANT = 'password_grant';
	const BASE_URL            = 'https://api.mysupership.vn';
	
	/** @var string */
	private $mode;
	
	/** @var string */
	private $base_url;
	
	// Personal Token mode
	/** @var string */
	private $personal_token;
	
	// Password Grant mode
	/** @var string */
	private $client_id;
	
	/** @var string */
	private $client_secret;
	
	/** @var string */
	private $username;
	
	/** @var string */
	private $password;
	
	/** @var string */
	private $partner_code;

	/**
	 * Constructor
	 * 
	 * @param string $mode Authentication mode
	 * @param array  $credentials Mode-specific credentials
	 * @param string $base_url API base URL (default: production)
	 */
	private function __construct( string $mode, array $credentials, string $base_url = self::BASE_URL ) {
		$this->mode     = $mode;
		$this->base_url = rtrim( $base_url, '/' );
		
		if ( self::MODE_PERSONAL_TOKEN === $mode ) {
			$this->personal_token = $credentials['personal_token'] ?? '';
		} else {
			$this->client_id      = $credentials['client_id'] ?? '';
			$this->client_secret  = $credentials['client_secret'] ?? '';
			$this->username       = $credentials['username'] ?? '';
			$this->password       = $credentials['password'] ?? '';
			$this->partner_code   = $credentials['partner_code'] ?? '';
		}
	}

	/**
	 * Create config from WordPress options
	 * 
	 * @return SuperShip_Auth_Config
	 */
	public static function from_options(): SuperShip_Auth_Config {
		$mode = get_option( 'supership_auth_mode', self::MODE_PERSONAL_TOKEN );
		
		if ( self::MODE_PERSONAL_TOKEN === $mode ) {
			$token = get_option( 'supership_personal_token', '' );
			// Decrypt if encrypted
			if ( 0 === strpos( $token, 'v1:' ) && class_exists( 'SuperShip_Secret_Storage' ) ) {
				$storage = new SuperShip_Secret_Storage();
				$token   = $storage->decrypt( $token );
			}
			return new self( $mode, array( 'personal_token' => $token ) );
		}
		
		// Password Grant mode
		$storage  = new SuperShip_Secret_Storage();
		$password = get_option( 'supership_password', '' );
		if ( 0 === strpos( $password, 'v1:' ) ) {
			$password = $storage->decrypt( $password );
		}
		
		$credentials = array(
			'client_id'      => get_option( 'supership_client_id', '' ),
			'client_secret'  => get_option( 'supership_client_secret', '' ),
			'username'       => get_option( 'supership_username', '' ),
			'password'       => $password,
			'partner_code'   => self::get_stored_partner_code( $storage ),
		);

		// Decrypt client_secret if encrypted
		if ( 0 === strpos( $credentials['client_secret'], 'v1:' ) ) {
			$credentials['client_secret'] = $storage->decrypt( $credentials['client_secret'] );
		}

		return new self( $mode, $credentials );
	}

	/**
	 * Get the stored Partner Code (Mã Bí Mật), decrypted.
	 *
	 * Unlike client_id/client_secret/username/password, the partner code is
	 * relevant regardless of auth mode (docs list it as an optional field on
	 * Register, Password Grant Login, Create Order, and Create Warehouse) -
	 * so it is read independently of SuperShip_Auth_Config::from_options().
	 *
	 * @param SuperShip_Secret_Storage|null $storage Optional storage instance (avoids re-instantiating).
	 * @return string Decrypted partner code, or '' if not set.
	 */
	public static function get_stored_partner_code( SuperShip_Secret_Storage $storage = null ): string {
		$value = get_option( 'supership_partner_code', '' );

		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, 'v1:' ) ) {
			$storage = $storage ?: new SuperShip_Secret_Storage();
			return $storage->decrypt( $value );
		}

		return $value;
	}

	/**
	 * Create config for testing (manual construction)
	 * 
	 * @param string $mode Mode constant
	 * @param array  $credentials Credentials array
	 * @return SuperShip_Auth_Config
	 */
	public static function create( string $mode, array $credentials ): SuperShip_Auth_Config {
		return new self( $mode, $credentials );
	}

	// Getters
	
	public function get_mode(): string {
		return $this->mode;
	}
	
	public function get_base_url(): string {
		return $this->base_url;
	}
	
	public function is_personal_token_mode(): bool {
		return self::MODE_PERSONAL_TOKEN === $this->mode;
	}
	
	public function is_password_grant_mode(): bool {
		return self::MODE_PASSWORD_GRANT === $this->mode;
	}
	
	/**
	 * Get Personal Access Token (mode: personal_token)
	 */
	public function get_personal_token(): string {
		return $this->personal_token ?? '';
	}
	
	/**
	 * Get Password Grant credentials (mode: password_grant)
	 */
	public function get_client_id(): string {
		return $this->client_id ?? '';
	}
	
	public function get_client_secret(): string {
		return $this->client_secret ?? '';
	}
	
	public function get_username(): string {
		return $this->username ?? '';
	}
	
	public function get_password(): string {
		return $this->password ?? '';
	}
	
	public function get_partner_code(): string {
		return $this->partner_code ?? '';
	}

	/**
	 * Check if credentials are complete for current mode
	 */
	public function has_credentials(): bool {
		if ( $this->is_personal_token_mode() ) {
			return '' !== $this->get_personal_token();
		}
		
		// Password Grant needs all fields
		return '' !== $this->get_client_id()
			&& '' !== $this->get_client_secret()
			&& '' !== $this->get_username()
			&& '' !== $this->get_password();
	}

	/**
	 * Safe array representation (no secrets exposed)
	 */
	public function to_safe_array(): array {
		$safe = array(
			'mode'             => $this->mode,
			'base_url'         => $this->base_url,
			'has_credentials'  => $this->has_credentials(),
		);
		
		if ( $this->is_personal_token_mode() ) {
			$safe['personal_token'] = $this->mask( $this->get_personal_token() );
		} else {
			$safe['client_id']     = $this->mask( $this->get_client_id() );
			$safe['client_secret'] = $this->mask( $this->get_client_secret() );
			$safe['username']      = $this->get_username(); // Email is not secret
			$safe['password']      = $this->mask( $this->get_password() );
			$safe['partner_code']  = $this->mask( $this->get_partner_code() );
		}
		
		return $safe;
	}

	/**
	 * Mask sensitive value for logging/display
	 */
	private function mask( string $value ): string {
		$len = strlen( $value );
		if ( 0 === $len ) {
			return '(empty)';
		}
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 4 ) . substr( $value, -4 );
	}

	public function __toString(): string {
		return sprintf( 'SuperShip_Auth_Config(mode=%s)', $this->mode );
	}

	public function __debugInfo(): array {
		return $this->to_safe_array();
	}
}
