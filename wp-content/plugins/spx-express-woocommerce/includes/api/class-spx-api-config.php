<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SPX_Environment' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-environment.php'; }
if ( ! class_exists( 'SPX_Secret_Storage' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-secret-storage.php'; }
if ( ! class_exists( 'SPX_Credential_Store' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-credential-store.php'; }

/**
 * Loads SPX API configuration from WordPress constants first, then environment
 * variables. Secrets are never exposed through __toString(), __debugInfo(),
 * to_safe_array(), or exceptions. Credentials are intentionally NOT read from
 * Shipping Zone instance settings (they belong to the SPX app/account, not a zone).
 */
final class SPX_API_Config {
	const TEST_ENV       = 'test';
	const PRODUCTION_ENV = 'production';
	const TEST_BASE_URL  = 'https://test-stable.spx.vn/';
	const PRODUCTION_BASE_URL = 'https://spx.vn/';

	/** @var string */ private $environment;
	/** @var string */ private $base_url;
	/** @var string */ private $app_id;
	/** @var string */ private $app_secret;
	/** @var string */ private $user_id;
	/** @var string */ private $user_secret;

	private function __construct( string $environment, string $base_url, string $app_id, string $app_secret, string $user_id, string $user_secret ) {
		$this->environment = $environment;
		$this->base_url    = $base_url;
		$this->app_id      = $app_id;
		$this->app_secret  = $app_secret;
		$this->user_id     = $user_id;
		$this->user_secret = $user_secret;
	}

	public static function for_test(): SPX_API_Config {
		return self::for_environment( self::TEST_ENV );
	}

	public static function for_production(): SPX_API_Config {
		return self::for_environment( self::PRODUCTION_ENV );
	}

	public static function for_environment( string $environment, SPX_Credential_Store $store = null ): SPX_API_Config {
		$environment = SPX_Environment::normalize( $environment );
		$store = $store ?: new SPX_Credential_Store(); $credentials = $store->get( $environment );
		return new self( $environment, SPX_Environment::base_url( $environment ), $credentials['app_id'], $credentials['app_secret'], $credentials['user_id'], $credentials['user_secret'] );
	}

	public function get_environment(): string { return $this->environment; }
	public function get_base_url(): string { return $this->base_url; }
	public function get_app_id(): string { return $this->app_id; }

	/* Secret accessors — for the signer/HTTP client only; never logged/dumped. */
	public function get_app_secret(): string { return $this->app_secret; }
	public function get_user_id(): string { return $this->user_id; }
	public function get_user_secret(): string { return $this->user_secret; }

	public function has_required_credentials(): bool {
		return '' !== $this->base_url && '' !== $this->app_id && '' !== $this->app_secret;
	}

	public function has_account_credentials(): bool {
		return $this->has_required_credentials() && '' !== $this->user_id && '' !== $this->user_secret;
	}

	/** In test mode the base URL must be exactly the official sandbox host. */
	public function is_valid(): bool {
		if ( ! $this->has_required_credentials() ) { return false; }
		return SPX_Environment::base_url( $this->environment ) === rtrim( $this->base_url, '/' ) . '/';
	}

	/** Masked, secret-free view suitable for logs or debugging. */
	public function to_safe_array(): array {
		return array(
			'environment'     => $this->environment,
			'base_url'        => $this->base_url,
			'app_id'          => $this->app_id,
			'app_secret'      => $this->mask( $this->app_secret ),
			'user_id'         => $this->mask( $this->user_id ),
			'user_secret'     => $this->mask( $this->user_secret ),
			'has_credentials' => $this->has_required_credentials(),
		);
	}

	private function mask( string $value ): string {
		$len = strlen( $value );
		if ( 0 === $len ) { return '(empty)'; }
		if ( $len <= 4 ) { return str_repeat( '*', $len ); }
		return str_repeat( '*', $len - 4 ) . substr( $value, -4 );
	}

	public function __toString(): string {
		return 'SPX_API_Config(' . $this->environment . ')';
	}

	public function __debugInfo(): array {
		return $this->to_safe_array();
	}
}
