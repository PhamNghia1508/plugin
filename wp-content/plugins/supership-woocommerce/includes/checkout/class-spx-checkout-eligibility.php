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

	/**
	 * Whether the Khu vực control should RENDER as a visible field on Checkout.
	 * Decoupled from validation: the field must appear whenever the cart needs
	 * shipping and SPX is an option in the matched zone, so the customer can
	 * pick a location BEFORE a shipping rate is computed. Without this, the old
	 * "require chosen method → then show field" creates a chicken/egg where the
	 * field never appears on first load.
	 */
	public static function should_render_field( bool $needs_shipping, bool $spx_chosen, bool $spx_available_in_zone ): bool {
		if ( ! $needs_shipping ) { return false; }
		return $spx_chosen || $spx_available_in_zone;
	}

	public static function current_checkout_should_render_field(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) { return false; }
		$needs_shipping = (bool) WC()->cart->needs_shipping();
		if ( ! $needs_shipping ) { return false; }
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$spx_chosen = false;
		foreach ( $chosen as $m ) {
			if ( 'spx_express' === strtolower( trim( explode( ':', (string) $m, 2 )[0] ) ) ) { $spx_chosen = true; break; }
		}
		return self::should_render_field( true, $spx_chosen, self::spx_available_in_current_package() );
	}

	private static function spx_available_in_current_package(): bool {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) { return false; }
		$packages = method_exists( WC()->cart, 'get_shipping_packages' ) ? (array) WC()->cart->get_shipping_packages() : array( array() );
		foreach ( $packages as $package ) {
			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
			if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) { continue; }
			foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
				if ( is_object( $method ) && 'spx_express' === (string) ( $method->id ?? '' ) ) { return true; }
			}
		}
		return false;
	}
}
