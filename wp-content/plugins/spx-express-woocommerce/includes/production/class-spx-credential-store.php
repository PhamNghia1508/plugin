<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Credential_Store {
	/** @var SPX_Secret_Storage */ private $storage;
	public function __construct( SPX_Secret_Storage $storage = null ) { $this->storage = $storage ?: new SPX_Secret_Storage(); }

	public static function option_name( string $environment ): string { return 'spx_credentials_' . SPX_Environment::normalize( $environment ); }

	public function get( string $environment ): array {
		$environment = SPX_Environment::normalize( $environment );
		if ( '' === $environment ) { return self::empty_credentials(); }
		$stored = function_exists( 'get_option' ) ? get_option( self::option_name( $environment ), array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();
		$result = self::empty_credentials();
		foreach ( array_keys( $result ) as $field ) {
			$server = self::server_value( $environment, $field );
			if ( '' !== $server ) { $result[ $field ] = $server; continue; }
			$value = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? trim( (string) $stored[ $field ] ) : '';
			$result[ $field ] = in_array( $field, array( 'app_secret', 'user_secret' ), true ) ? $this->storage->decrypt( $value ) : $value;
		}
		return $result;
	}

	public function source( string $environment, string $field ): string {
		return '' !== self::server_value( $environment, $field ) ? 'server' : 'option';
	}

	public function save( string $environment, array $values, array $delete = array() ): bool {
		$environment = SPX_Environment::normalize( $environment );
		if ( '' === $environment || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) { return false; }
		$current = get_option( self::option_name( $environment ), array() ); $current = is_array( $current ) ? $current : array();
		foreach ( array_keys( self::empty_credentials() ) as $field ) {
			if ( 'yes' === ( $delete[ $field ] ?? '' ) ) { unset( $current[ $field ] ); continue; }
			if ( ! array_key_exists( $field, $values ) || '' === trim( (string) $values[ $field ] ) ) { continue; }
			if ( 'server' === $this->source( $environment, $field ) ) { continue; }
			$value = trim( (string) $values[ $field ] );
			if ( in_array( $field, array( 'app_secret', 'user_secret' ), true ) ) { $value = $this->storage->encrypt( $value ); if ( '' === $value ) { return false; } }
			$current[ $field ] = $value;
		}
		$option = self::option_name( $environment ); $before = get_option( $option, array() );
		return $before === $current || (bool) update_option( $option, $current, false );
	}

	public function fingerprint( string $environment ): string {
		$c = $this->get( $environment );
		return $this->storage->fingerprint( SPX_Environment::normalize( $environment ) . '|' . implode( '|', $c ) . '|schema-v1' );
	}

	private static function server_value( string $environment, string $field ): string {
		$prefix = SPX_Environment::TEST === $environment ? 'SPX_TEST_' : 'SPX_PRODUCTION_';
		$name = $prefix . strtoupper( $field );
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) { return trim( (string) constant( $name ) ); }
		$value = getenv( $name ); return false === $value ? '' : trim( (string) $value );
	}

	private static function empty_credentials(): array { return array( 'app_id' => '', 'app_secret' => '', 'user_id' => '', 'user_secret' => '', 'shop_id' => '' ); }
}
