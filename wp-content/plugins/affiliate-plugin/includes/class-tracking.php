<?php
defined( 'ABSPATH' ) || exit;

/**
 * Click tracking: turns `?ref=CODE` on a product page into a cookie that
 * survives until the visitor checks out.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Tracking {

	const COOKIE  = 'wc_aff_ref';
	const PARAM   = 'ref';
	/** Cookie lifetime in days (filterable via `wc_affiliate_cookie_days`). */
	const DAYS    = 30;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'capture_referral' ) );
	}

	/**
	 * How long the referral cookie lives, in days.
	 */
	public static function cookie_days(): int {
		return (int) apply_filters( 'wc_affiliate_cookie_days', self::DAYS );
	}

	/**
	 * Store the referral when a visitor lands on a product page via `?ref=`.
	 *
	 * Deliberately only records on product pages: commission is paid for the
	 * specific product in the link, so a referral without a product could never
	 * pay out anyway.
	 *
	 * Last click wins - a visitor who follows two different affiliate links
	 * before buying is attributed to the most recent one, which is the standard
	 * behaviour affiliates expect.
	 */
	public static function capture_referral(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link, nothing to forge.
		$code = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';

		if ( '' === $code || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$affiliate = WC_Affiliate_Repo::get_by_ref_code( $code );

		// Unknown code, or the affiliate is still pending / has been disabled:
		// ignore silently. The visitor still sees a perfectly normal product page.
		if ( ! WC_Affiliate_Repo::is_active( $affiliate ) ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return;
		}

		self::set_cookie(
			array(
				'affiliate_id' => (int) $affiliate['id'],
				'product_id'   => (int) $product_id,
			)
		);
	}

	/**
	 * Write the referral cookie.
	 *
	 * @param array $data affiliate_id + product_id.
	 */
	private static function set_cookie( array $data ): void {
		$value   = wp_json_encode( $data );
		$expires = time() + ( self::cookie_days() * DAY_IN_SECONDS );

		// Keep the in-memory copy in sync so the same request can read it back.
		$_COOKIE[ self::COOKIE ] = $value;

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Read the stored referral, if any and still valid.
	 *
	 * @return array{affiliate_id:int, product_id:int}|null
	 */
	public static function get_referral(): ?array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$raw  = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['affiliate_id'] ) || empty( $data['product_id'] ) ) {
			return null;
		}

		return array(
			'affiliate_id' => (int) $data['affiliate_id'],
			'product_id'   => (int) $data['product_id'],
		);
	}

	/**
	 * Drop the cookie once it has been attached to an order, so the next
	 * purchase from the same browser isn't credited to the same affiliate all
	 * over again.
	 */
	public static function clear_cookie(): void {
		unset( $_COOKIE[ self::COOKIE ] );

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Build the referral link for a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $ref_code   Affiliate's referral code.
	 * @return string
	 */
	public static function build_link( int $product_id, string $ref_code ): string {
		return add_query_arg( self::PARAM, rawurlencode( $ref_code ), get_permalink( $product_id ) );
	}
}
