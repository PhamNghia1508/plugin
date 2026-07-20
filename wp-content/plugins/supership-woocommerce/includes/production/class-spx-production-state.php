<?php
defined( 'ABSPATH' ) || exit;
final class SPX_Production_State {
	const DISABLED = 'disabled'; const READINESS_PENDING = 'readiness_pending'; const VERIFIED = 'verified'; const ENABLED = 'enabled';
	public static function normalize( $state ): string { return in_array( $state, array( self::DISABLED, self::READINESS_PENDING, self::VERIFIED, self::ENABLED ), true ) ? $state : self::DISABLED; }
	public static function offline_transition( string $current, string $requested ): string {
		$current = self::normalize( $current );
		return self::DISABLED === $requested ? self::DISABLED : ( self::READINESS_PENDING === $requested ? self::READINESS_PENDING : $current );
	}
	public static function verification_matches( string $verified_fingerprint, string $current_fingerprint ): bool { return '' !== $verified_fingerprint && hash_equals( $verified_fingerprint, $current_fingerprint ); }
	public static function production_network_allowed( string $state, string $verified_fingerprint, string $current_fingerprint ): bool {
		return self::ENABLED === self::normalize( $state ) && self::verification_matches( $verified_fingerprint, $current_fingerprint );
	}
}
