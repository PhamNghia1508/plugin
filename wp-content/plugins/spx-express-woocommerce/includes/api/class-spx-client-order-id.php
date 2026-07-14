<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds a deterministic, PII-free client order id for SPX `order_id`
 * (max 32 chars). Format: WC-{blog_id}-{envcode}-{order_id}. Same order always
 * yields the same id (idempotency); test and production use different envcodes.
 */
final class SPX_Client_Order_ID {
	const MAX_LEN = 32;

	public static function for_order( $order, string $environment ): string {
		$order_id = $order instanceof WC_Order ? (int) $order->get_id() : (int) $order;
		$blog_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return self::build( $blog_id, $environment, $order_id );
	}

	public static function build( int $blog_id, string $environment, int $order_id ): string {
		$env = ( SPX_API_Config::PRODUCTION_ENV === $environment ) ? 'P' : 'T';
		$id  = 'WC-' . $blog_id . '-' . $env . '-' . $order_id;
		// Guard the documented 32-char limit deterministically (no PII, no random).
		if ( strlen( $id ) > self::MAX_LEN ) {
			$id = 'WC-' . $env . '-' . substr( hash( 'sha1', $blog_id . '-' . $order_id ), 0, 24 );
		}
		return $id;
	}
}
