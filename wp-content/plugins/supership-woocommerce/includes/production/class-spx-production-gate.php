<?php
defined( 'ABSPATH' ) || exit;

/**
 * Operation-aware Production enablement gate. Replaces the previous
 * unconditional "production is disabled" kill-switches with a per-operation
 * decision:
 *
 *   - account_verify may run BEFORE a marker exists, but only from an explicit
 *     admin HTTPS request (bootstrap policy) — never from the frontend or cron.
 *   - every other operation (rate/create/search/tracking/label/cancel) requires
 *     a valid verification marker whose credential fingerprint and host still
 *     match the live configuration.
 *
 * Transport-level security (host allowlist, HTTPS scheme, port 443, sslverify,
 * no redirects) is enforced separately and unconditionally by SPX_HTTP_Client;
 * this gate only decides whether an operation is permitted to reach it.
 */
final class SPX_Production_Gate {
	const OPERATIONS          = array( 'account_verify', 'rate', 'create', 'search', 'tracking', 'label', 'cancel' );
	const BOOTSTRAP_OPERATION = 'account_verify';

	/** Map an SPX API path to its operation name (or '' if unknown). */
	public static function operation_for_path( string $path ): string {
		$map = array(
			'/open/api/v1/account/verify'                 => 'account_verify',
			'/open/api/v1/order/batch_check_order'        => 'rate',
			'/open/api/v1/order/batch_create_order'       => 'create',
			'/open/api/v1/order/batch_search_order'       => 'search',
			'/open/api/v1/order/batch_get_shipping_label' => 'label',
			'/open/api/v1/order/batch_cancel_order'       => 'cancel',
		);
		return $map[ $path ] ?? '';
	}

	/**
	 * @param array $ctx { admin, frontend, cron, is_ssl, home_https, site_https,
	 *                     has_credentials, fingerprint, host }
	 * @return array{allowed:bool,reason:string}
	 */
	public static function evaluate( string $operation, array $ctx ): array {
		if ( ! in_array( $operation, self::OPERATIONS, true ) ) { return self::deny( 'unknown_operation' ); }

		// Every Production operation requires an HTTPS-configured site, live
		// credentials, and a resolved production host — always enforced.
		if ( empty( $ctx['home_https'] ) || empty( $ctx['site_https'] ) ) { return self::deny( 'https_required' ); }
		if ( empty( $ctx['has_credentials'] ) ) { return self::deny( 'missing_credentials' ); }
		if ( '' === (string) ( $ctx['host'] ?? '' ) ) { return self::deny( 'host_unresolved' ); }

		if ( self::BOOTSTRAP_OPERATION === $operation ) {
			// Bootstrap: allowed pre-marker, but only from an explicit admin HTTPS request.
			if ( ! empty( $ctx['cron'] ) ) { return self::deny( 'cron_forbidden' ); }
			if ( ! empty( $ctx['frontend'] ) ) { return self::deny( 'frontend_forbidden' ); }
			if ( empty( $ctx['admin'] ) ) { return self::deny( 'admin_context_required' ); }
			if ( empty( $ctx['is_ssl'] ) ) { return self::deny( 'insecure_request' ); }
			return self::allow();
		}

		// All other operations require a valid, matching verification marker.
		$fingerprint = (string) ( $ctx['fingerprint'] ?? '' );
		$host        = (string) ( $ctx['host'] ?? '' );
		if ( ! SPX_Production_Verification_Store::is_verified( $fingerprint, $host ) ) {
			$state = SPX_Production_Verification_Store::state( $fingerprint, $host );
			return self::deny( 'invalidated' === $state ? 'verification_invalidated' : 'verification_required' );
		}
		return self::allow();
	}

	public static function allows( string $operation, array $ctx ): bool {
		return ! empty( self::evaluate( $operation, $ctx )['allowed'] );
	}

	/** Build the live runtime context from WordPress + credential state. */
	public static function runtime_context(): array {
		$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		$site = function_exists( 'site_url' ) ? (string) site_url() : '';
		$cron = self::is_cron();
		return array(
			'admin'           => function_exists( 'is_admin' ) && is_admin() && ! $cron,
			'frontend'        => function_exists( 'is_admin' ) ? ! is_admin() : false,
			'cron'            => $cron,
			'is_ssl'          => function_exists( 'is_ssl' ) ? (bool) is_ssl() : false,
			'home_https'      => 0 === strpos( $home, 'https://' ),
			'site_https'      => 0 === strpos( $site, 'https://' ),
			'has_credentials' => self::has_production_credentials(),
			'fingerprint'     => self::current_fingerprint(),
			'host'            => class_exists( 'SPX_Environment' ) ? SPX_Environment::host( SPX_Environment::PRODUCTION ) : '',
		);
	}

	public static function current_fingerprint(): string {
		if ( ! class_exists( 'SPX_Credential_Store' ) || ! class_exists( 'SPX_Environment' ) ) { return ''; }
		return ( new SPX_Credential_Store() )->fingerprint( SPX_Environment::PRODUCTION );
	}

	private static function has_production_credentials(): bool {
		if ( ! class_exists( 'SPX_API_Config' ) ) { return false; }
		return SPX_API_Config::for_production()->has_account_credentials();
	}

	private static function is_cron(): bool {
		return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
	}

	private static function allow(): array { return array( 'allowed' => true, 'reason' => '' ); }
	private static function deny( string $reason ): array { return array( 'allowed' => false, 'reason' => $reason ); }
}
