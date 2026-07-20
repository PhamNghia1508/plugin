<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Secret Storage
 * 
 * Handles encryption/decryption of sensitive data (tokens, credentials)
 * using Sodium (preferred) or OpenSSL fallback with AES-256-GCM.
 * 
 * Copied from SPX implementation - encryption logic is carrier-independent.
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Secret_Storage {
	const CONTEXT = 'supership-woocommerce/credentials/v1';
	
	/** @var string Binary encryption key */
	private $key;

	/**
	 * @param string $salt_material Optional salt material (defaults to WP salts)
	 */
	public function __construct( string $salt_material = '' ) {
		if ( '' === $salt_material ) {
			$salt_material = self::wordpress_salts();
		}
		$this->key = hash_hmac( 'sha256', self::CONTEXT, $salt_material, true );
	}

	/**
	 * Encrypt plaintext string
	 * 
	 * @param string $plaintext Data to encrypt
	 * @return string Encrypted payload (v1:sodium:base64 or v1:aesgcm:base64) or empty on failure
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		// Prefer Sodium (PHP 7.2+)
		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
				return 'v1:sodium:' . base64_encode( $nonce . $cipher );
			} catch ( Throwable $e ) {
				return '';
			}
		}

		// Fallback to OpenSSL AES-256-GCM
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			try {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt(
					$plaintext,
					'aes-256-gcm',
					$this->key,
					OPENSSL_RAW_DATA,
					$iv,
					$tag,
					self::CONTEXT,
					16
				);
				if ( false === $cipher ) {
					return '';
				}
				return 'v1:aesgcm:' . base64_encode( $iv . $tag . $cipher );
			} catch ( Throwable $e ) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Decrypt encrypted payload
	 * 
	 * @param string $payload Encrypted string (v1:sodium:... or v1:aesgcm:...)
	 * @return string Decrypted plaintext or empty on failure/invalid format
	 */
	public function decrypt( string $payload ): string {
		// Sodium format
		if ( 0 === strpos( $payload, 'v1:sodium:' ) ) {
			$raw = base64_decode( substr( $payload, 10 ), true );
			if ( false === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			try {
				$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$value = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key );
				return false === $value ? '' : $value;
			} catch ( Throwable $e ) {
				return '';
			}
		}

		// OpenSSL AES-256-GCM format
		if ( 0 === strpos( $payload, 'v1:aesgcm:' ) ) {
			$raw = base64_decode( substr( $payload, 10 ), true );
			if ( false === $raw || ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			if ( strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$value  = openssl_decrypt( $cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT );
			return false === $value ? '' : $value;
		}

		return '';
	}

	/**
	 * Generate HMAC fingerprint of a value (for comparison, not encryption)
	 * 
	 * @param string $value Value to fingerprint
	 * @return string Hex-encoded HMAC-SHA256
	 */
	public function fingerprint( string $value ): string {
		return hash_hmac( 'sha256', $value, $this->key );
	}

	/**
	 * Build salt material from WordPress security constants
	 * 
	 * @return string Concatenated WordPress salts
	 */
	private static function wordpress_salts(): string {
		$names = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);
		$parts = array();
		foreach ( $names as $name ) {
			$parts[] = defined( $name ) ? (string) constant( $name ) : $name;
		}
		return implode( '|', $parts );
	}
}
