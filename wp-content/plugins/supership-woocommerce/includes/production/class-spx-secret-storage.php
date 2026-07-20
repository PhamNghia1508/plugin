<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Secret_Storage {
	const CONTEXT = 'spx-express-woocommerce/credentials/v1';
	/** @var string */ private $key;

	public function __construct( string $salt_material = '' ) {
		if ( '' === $salt_material ) { $salt_material = self::wordpress_salts(); }
		$this->key = hash_hmac( 'sha256', self::CONTEXT, $salt_material, true );
	}

	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) { return ''; }
		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			try {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
				return 'v1:sodium:' . base64_encode( $nonce . $cipher );
			} catch ( Throwable $e ) { return ''; }
		}
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			try {
				$iv = random_bytes( 12 ); $tag = '';
				$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT, 16 );
				return false === $cipher ? '' : 'v1:aesgcm:' . base64_encode( $iv . $tag . $cipher );
			} catch ( Throwable $e ) { return ''; }
		}
		return '';
	}

	public function decrypt( string $payload ): string {
		if ( 0 === strpos( $payload, 'v1:sodium:' ) ) {
			$raw = base64_decode( substr( $payload, 10 ), true );
			if ( false === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return ''; }
			try {
				$value = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key );
				return false === $value ? '' : $value;
			} catch ( Throwable $e ) { return ''; }
		}
		if ( 0 === strpos( $payload, 'v1:aesgcm:' ) ) {
			$raw = base64_decode( substr( $payload, 10 ), true );
			if ( false === $raw || ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) <= 28 ) { return ''; }
			$value = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), self::CONTEXT );
			return false === $value ? '' : $value;
		}
		return '';
	}

	public function fingerprint( string $value ): string { return hash_hmac( 'sha256', $value, $this->key ); }

	private static function wordpress_salts(): string {
		$names = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$parts = array();
		foreach ( $names as $name ) { $parts[] = defined( $name ) ? (string) constant( $name ) : $name; }
		return implode( '|', $parts );
	}
}
