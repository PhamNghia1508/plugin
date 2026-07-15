<?php
defined( 'ABSPATH' ) || exit;

/** Canonical, non-lossy recipient-name resolution for WooCommerce orders. */
final class SPX_Order_Name_Resolver {
	const META_FULL_NAME = '_spx_billing_full_name';

	public static function recipient_name( WC_Order $order ): string {
		$full_name = self::normalize( (string) $order->get_meta( self::META_FULL_NAME, true ) );
		if ( '' !== $full_name ) { return $full_name; }

		$shipping_name = self::normalize(
			(string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name()
		);
		if ( '' !== $shipping_name ) { return $shipping_name; }

		return self::normalize(
			(string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name()
		);
	}

	private static function normalize( string $name ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $name ) ) );
	}
}
