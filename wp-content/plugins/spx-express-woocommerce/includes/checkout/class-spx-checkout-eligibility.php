<?php
defined( 'ABSPATH' ) || exit;

/** Pure eligibility rules shared by Classic and Store API checkout. */
final class SPX_Checkout_Eligibility {
	public static function requires_selection( bool $needs_shipping, array $chosen_methods ): bool {
		if ( ! $needs_shipping ) { return false; }
		foreach ( $chosen_methods as $method ) {
			$base = strtolower( trim( explode( ':', (string) $method, 2 )[0] ) );
			if ( 'spx_express' === $base ) { return true; }
		}
		return false;
	}

	public static function current_checkout_requires_selection(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) { return false; }
		$needs_shipping = WC()->cart->needs_shipping();
		$chosen          = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		return self::requires_selection( (bool) $needs_shipping, $chosen );
	}
}
