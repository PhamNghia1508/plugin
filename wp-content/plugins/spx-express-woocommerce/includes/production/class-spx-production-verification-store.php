<?php
defined( 'ABSPATH' ) || exit;

/**
 * Persists the SPX Production Account-Verify marker. Stores only non-secret,
 * one-way data: a schema version, the verified flag/time, the production host,
 * and an HMAC credential fingerprint (never the credentials themselves). The
 * marker is fail-closed: an old schema, a host change, or a credential
 * fingerprint mismatch all cause is_verified() to return false so that every
 * gated Production operation is blocked until Account Verify is re-run.
 */
final class SPX_Production_Verification_Store {
	const OPTION         = 'spx_production_verification';
	const SCHEMA_VERSION = 1;

	/** @return array Normalised marker (never contains secrets). */
	public static function get(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	private static function normalize( array $m ): array {
		return array(
			'schema_version'         => isset( $m['schema_version'] ) ? (int) $m['schema_version'] : 0,
			'verified'               => ! empty( $m['verified'] ),
			'verified_at'            => isset( $m['verified_at'] ) ? (string) $m['verified_at'] : '',
			'host'                   => isset( $m['host'] ) ? (string) $m['host'] : '',
			'credential_fingerprint' => isset( $m['credential_fingerprint'] ) ? (string) $m['credential_fingerprint'] : '',
			'state'                  => isset( $m['state'] ) ? (string) $m['state'] : 'disabled',
			'reason'                 => isset( $m['reason'] ) ? (string) $m['reason'] : '',
		);
	}

	/** Persist a successful verification. Only a fingerprint + host are stored. */
	public static function mark_verified( string $fingerprint, string $host ): bool {
		if ( '' === $fingerprint || '' === $host ) { return false; }
		$marker = array(
			'schema_version'         => self::SCHEMA_VERSION,
			'verified'               => true,
			'verified_at'            => gmdate( 'c' ),
			'host'                   => $host,
			'credential_fingerprint' => $fingerprint,
			'state'                  => 'verified',
			'reason'                 => '',
		);
		return function_exists( 'update_option' ) ? (bool) update_option( self::OPTION, $marker, false ) : false;
	}

	/** Fail-closed invalidation. Keeps the marker row but clears the verified flag. */
	public static function invalidate( string $reason ): void {
		$marker              = self::get();
		$marker['verified']  = false;
		$marker['state']     = 'invalidated';
		$marker['reason']    = function_exists( 'sanitize_key' ) ? sanitize_key( $reason ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $reason ) );
		if ( function_exists( 'update_option' ) ) { update_option( self::OPTION, $marker, false ); }
	}

	/**
	 * True only when the marker is verified, current-schema, and both the host
	 * and the credential fingerprint still match the live configuration.
	 */
	public static function is_verified( string $current_fingerprint, string $current_host ): bool {
		$m = self::get();
		if ( true !== $m['verified'] ) { return false; }
		if ( self::SCHEMA_VERSION !== $m['schema_version'] ) { return false; } // old schema -> fail closed
		if ( '' === $m['credential_fingerprint'] || '' === $current_fingerprint ) { return false; }
		if ( ! hash_equals( $m['credential_fingerprint'], $current_fingerprint ) ) { return false; }
		if ( '' === $m['host'] || $m['host'] !== $current_host ) { return false; }
		return true;
	}

	/** Human/state label; reports "invalidated" when the live config diverged. */
	public static function state( string $current_fingerprint = '', string $current_host = '' ): string {
		$m = self::get();
		if ( $m['verified'] && '' !== $current_fingerprint && ! self::is_verified( $current_fingerprint, $current_host ) ) {
			return 'invalidated';
		}
		return '' !== $m['state'] ? $m['state'] : 'disabled';
	}
}
