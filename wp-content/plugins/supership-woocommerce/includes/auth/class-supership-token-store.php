<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Token Store
 * 
 * Manages Bearer token lifecycle: storage, retrieval, expiry checking.
 * Tokens are stored in WordPress options with encrypted access_token.
 * 
 * Token structure:
 * - access_token (string, encrypted)
 * - token_type (string, usually "Bearer")
 * - expires_at (int, Unix timestamp)
 * - obtained_at (int, Unix timestamp when token was obtained)
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Token_Store {
	
	const OPTION_NAME = 'supership_bearer_token';
	const BUFFER_SECONDS = 300; // Refresh 5 minutes before expiry
	
	/** @var SuperShip_Secret_Storage */
	private $storage;

	public function __construct( SuperShip_Secret_Storage $storage = null ) {
		$this->storage = $storage ?: new SuperShip_Secret_Storage();
	}

	/**
	 * Get current token data
	 * 
	 * @return array|null Token data or null if not exists/invalid
	 */
	public function get(): ?array {
		$stored = get_option( self::OPTION_NAME, null );
		
		if ( ! is_array( $stored ) || empty( $stored['access_token'] ) ) {
			return null;
		}
		
		// Decrypt access_token if encrypted
		$token = $stored['access_token'];
		if ( 0 === strpos( $token, 'v1:' ) ) {
			$token = $this->storage->decrypt( $token );
			if ( '' === $token ) {
				return null; // Decryption failed
			}
		}
		
		return array(
			'access_token' => $token,
			'token_type'   => $stored['token_type'] ?? 'Bearer',
			'expires_at'   => isset( $stored['expires_at'] ) ? (int) $stored['expires_at'] : 0,
			'obtained_at'  => isset( $stored['obtained_at'] ) ? (int) $stored['obtained_at'] : 0,
		);
	}

	/**
	 * Save new token
	 * 
	 * @param string $access_token Access token from SuperShip
	 * @param string $token_type Token type (usually "Bearer")
	 * @param int    $expires_in Seconds until expiry (e.g., 31536000 = ~1 year)
	 * @return bool Success
	 */
	public function save( string $access_token, string $token_type, int $expires_in ): bool {
		if ( '' === $access_token ) {
			return false;
		}
		
		$now         = time();
		$expires_at  = $now + $expires_in;
		$encrypted   = $this->storage->encrypt( $access_token );
		
		if ( '' === $encrypted ) {
			// Encryption failed - don't store plaintext!
			return false;
		}
		
		$data = array(
			'access_token' => $encrypted,
			'token_type'   => $token_type,
			'expires_at'   => $expires_at,
			'obtained_at'  => $now,
		);
		
		return update_option( self::OPTION_NAME, $data, false );
	}

	/**
	 * Clear/delete stored token
	 * 
	 * @return bool Success
	 */
	public function clear(): bool {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Check if token exists
	 * 
	 * @return bool
	 */
	public function exists(): bool {
		return null !== $this->get();
	}

	/**
	 * Check if token is expired (or about to expire within buffer)
	 * 
	 * @param bool $use_buffer Apply buffer time (default: true, refresh 5min before expiry)
	 * @return bool True if expired or missing
	 */
	public function is_expired( bool $use_buffer = true ): bool {
		$token = $this->get();
		
		if ( null === $token || empty( $token['access_token'] ) ) {
			return true; // No token = expired
		}
		
		$expires_at = $token['expires_at'] ?? 0;
		if ( $expires_at <= 0 ) {
			return true; // No expiry = treat as expired
		}
		
		$threshold = time();
		if ( $use_buffer ) {
			$threshold += self::BUFFER_SECONDS;
		}
		
		return $expires_at <= $threshold;
	}

	/**
	 * Get time remaining until expiry (seconds)
	 * 
	 * @return int Seconds remaining, or 0 if expired/missing
	 */
	public function time_until_expiry(): int {
		$token = $this->get();
		
		if ( null === $token ) {
			return 0;
		}
		
		$expires_at = $token['expires_at'] ?? 0;
		$remaining  = $expires_at - time();
		
		return max( 0, $remaining );
	}

	/**
	 * Get token fingerprint (for comparing if token changed)
	 * 
	 * @return string HMAC fingerprint of token, or empty if no token
	 */
	public function fingerprint(): string {
		$token = $this->get();
		
		if ( null === $token || empty( $token['access_token'] ) ) {
			return '';
		}
		
		return $this->storage->fingerprint( $token['access_token'] );
	}

	/**
	 * Get human-readable expiry status
	 * 
	 * @return string Status: "valid", "expiring_soon", "expired", "missing"
	 */
	public function get_status(): string {
		if ( ! $this->exists() ) {
			return 'missing';
		}
		
		if ( $this->is_expired( false ) ) {
			return 'expired';
		}
		
		if ( $this->is_expired( true ) ) {
			return 'expiring_soon';
		}
		
		return 'valid';
	}

	/**
	 * Get safe token info for display/logging (no plaintext token)
	 * 
	 * @return array
	 */
	public function to_safe_array(): array {
		$token = $this->get();
		
		if ( null === $token ) {
			return array(
				'exists'   => false,
				'status'   => 'missing',
			);
		}
		
		$remaining = $this->time_until_expiry();
		
		return array(
			'exists'         => true,
			'status'         => $this->get_status(),
			'token_type'     => $token['token_type'],
			'expires_at'     => $token['expires_at'],
			'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', $token['expires_at'] ),
			'obtained_at'    => $token['obtained_at'],
			'time_remaining' => $remaining,
			'fingerprint'    => $this->fingerprint(),
		);
	}
}
