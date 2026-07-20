<?php
defined( 'ABSPATH' ) || exit;

/** Short-lived PII-free checkout quote cache with same-request coalescing. */
final class SPX_Checkout_Rate_Cache {
	const TTL = 240;
	/** @var array<string,array> */ private static $request_cache = array();

	public function make_key( array $parts ): string {
		$allowed = array( 'environment', 'sender_location', 'recipient_location', 'service_type', 'weight_grams', 'dimensions', 'cart_hash', 'package_hash', 'is_cod', 'cod_amount', 'contract_version', 'unit_mode', 'parcel_policy', 'parcel_policy_version', 'parcel_defaults' );
		$canonical = array();
		foreach ( $allowed as $key ) { $canonical[ $key ] = isset( $parts[ $key ] ) ? $parts[ $key ] : ''; }
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $canonical ) : json_encode( $canonical );
		return 'spx_rate_' . hash( 'sha256', $json );
	}

	public function get( string $key ) {
		if ( isset( self::$request_cache[ $key ] ) ) { return self::$request_cache[ $key ]; }
		$value = get_transient( $key );
		if ( ! is_array( $value ) || empty( $value['success'] ) ) { return null; }
		self::$request_cache[ $key ] = $value;
		return $value;
	}

	public function set( string $key, array $quote ): bool {
		$quote = $this->sanitize_quote( $quote );
		if ( empty( $quote['success'] ) ) { return false; }
		self::$request_cache[ $key ] = $quote;
		return (bool) set_transient( $key, $quote, self::TTL );
	}

	public function remember( string $key, callable $resolver ): array {
		$cached = $this->get( $key );
		if ( is_array( $cached ) ) { $cached['cache_hit'] = true; return $cached; }
		$lock_key = 'lock_' . substr( hash( 'sha256', $key ), 0, 32 );
		$locked = function_exists( 'wp_cache_add' ) ? wp_cache_add( $lock_key, 1, 'spx_rate_locks', 10 ) : true;
		if ( ! $locked ) { return array( 'success'=>false, 'error_code'=>'quote_in_progress', 'cache_hit'=>false ); }
		try { $result = $resolver(); }
		finally { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $lock_key, 'spx_rate_locks' ); } }
		if ( ! is_array( $result ) ) { $result = array( 'success' => false, 'error_code' => 'invalid_cache_value' ); }
		$result['cache_hit'] = false;
		if ( ! empty( $result['success'] ) ) { $this->set( $key, $result ); }
		return $result;
	}

	private function sanitize_quote( array $quote ): array {
		foreach ( array_keys( $quote ) as $key ) {
			if ( preg_match( '/secret|signature|phone|address|request|response/i', (string) $key ) ) { unset( $quote[ $key ] ); }
		}
		return $quote;
	}
}
