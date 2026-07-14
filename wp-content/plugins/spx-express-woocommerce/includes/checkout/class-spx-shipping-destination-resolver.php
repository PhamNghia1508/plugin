<?php
defined( 'ABSPATH' ) || exit;

/**
 * Canonical shipping destination resolver.
 *
 * Shared by Classic and Blocks adapters. Determines the correct
 * address source for SPX rate calculation and shipment creation.
 *
 * Precedence:
 *   1. shipping address (when "ship to different address" is checked)
 *   2. billing address
 *   3. blocked (incomplete data)
 *
 * Never mixes billing province with shipping ward.
 */
final class SPX_Shipping_Destination_Resolver {
	const SOURCE_SHIPPING = 'shipping';
	const SOURCE_BILLING  = 'billing';
	const SOURCE_BLOCKED  = 'blocked';

	/**
	 * Resolve the canonical destination from an order.
	 *
	 * @param WC_Order $order
	 * @return array{source:string,province_id:string,district_id:string,ward_id:string,address:string,name:string,phone:string}
	 */
	public static function from_order( WC_Order $order ): array {
		$has_shipping = '' !== trim( (string) $order->get_shipping_address_1() )
			|| '' !== trim( (string) $order->get_shipping_state() )
			|| '' !== trim( (string) $order->get_shipping_city() );

		if ( $has_shipping ) {
			return self::build_from_order( $order, self::SOURCE_SHIPPING );
		}

		return self::build_from_order( $order, self::SOURCE_BILLING );
	}

	/**
	 * Resolve the canonical destination from checkout data.
	 *
	 * @param array $data Checkout posted data.
	 * @return array{source:string,province_id:string,district_id:string,ward_id:string}
	 */
	public static function from_checkout_data( array $data ): array {
		$ship_to_different = ! empty( $data['ship_to_different_address'] );

		// SPX canonical IDs are always in the spx_shipping_* fields.
		// The source determines which WC address fields are the reference.
		$province_id = isset( $data['spx_shipping_province_id'] ) ? sanitize_text_field( (string) $data['spx_shipping_province_id'] ) : '';
		$district_id = isset( $data['spx_shipping_district_id'] ) ? sanitize_text_field( (string) $data['spx_shipping_district_id'] ) : '';
		$ward_id     = isset( $data['spx_shipping_ward_id'] ) ? sanitize_text_field( (string) $data['spx_shipping_ward_id'] ) : '';

		if ( '' === $province_id || '' === $district_id || '' === $ward_id ) {
			return self::blocked( 'incomplete_spx_location' );
		}

		return array(
			'source'      => $ship_to_different ? self::SOURCE_SHIPPING : self::SOURCE_BILLING,
			'province_id' => $province_id,
			'district_id' => $district_id,
			'ward_id'     => $ward_id,
		);
	}

	/**
	 * Resolve destination from WC session / customer object.
	 *
	 * @return array{source:string,province_id:string,district_id:string,ward_id:string}
	 */
	public static function from_session(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return self::blocked( 'no_session' );
		}

		$selection = (array) WC()->session->get( 'spx_shipping_address_selection', array() );
		$province_id = isset( $selection['province_id'] ) ? (string) $selection['province_id'] : '';
		$district_id = isset( $selection['district_id'] ) ? (string) $selection['district_id'] : '';
		$ward_id     = isset( $selection['ward_id'] ) ? (string) $selection['ward_id'] : '';

		if ( '' === $province_id || '' === $district_id || '' === $ward_id ) {
			return self::blocked( 'incomplete_session_location' );
		}

		// Determine source: check if customer is shipping to different address.
		$customer = WC()->customer;
		$has_shipping = $customer
			&& ( '' !== trim( (string) $customer->get_shipping_address_1() )
				|| '' !== trim( (string) $customer->get_shipping_state() ) );

		return array(
			'source'      => $has_shipping ? self::SOURCE_SHIPPING : self::SOURCE_BILLING,
			'province_id' => $province_id,
			'district_id' => $district_id,
			'ward_id'     => $ward_id,
		);
	}

	/**
	 * Build destination from order meta.
	 *
	 * @param WC_Order $order
	 * @param string   $source
	 * @return array
	 */
	private static function build_from_order( WC_Order $order, string $source ): array {
		$province_id = (string) $order->get_meta( '_spx_province_id', true );
		$district_id = (string) $order->get_meta( '_spx_district_id', true );
		$ward_id     = (string) $order->get_meta( '_spx_ward_id', true );

		if ( '' === $province_id || '' === $district_id || '' === $ward_id ) {
			return self::blocked( 'incomplete_order_spx_location' );
		}

		$prefix = self::SOURCE_SHIPPING === $source ? 'shipping' : 'billing';

		return array(
			'source'      => $source,
			'province_id' => $province_id,
			'district_id' => $district_id,
			'ward_id'     => $ward_id,
			'address'     => trim( (string) call_user_func( array( $order, 'get_' . $prefix . '_address_1' ) ) . ' ' . (string) call_user_func( array( $order, 'get_' . $prefix . '_address_2' ) ) ),
			'name'        => trim( (string) call_user_func( array( $order, 'get_' . $prefix . '_first_name' ) ) . ' ' . (string) call_user_func( array( $order, 'get_' . $prefix . '_last_name' ) ) ),
			'phone'       => self::SOURCE_SHIPPING === $source && method_exists( $order, 'get_shipping_phone' )
				? (string) $order->get_shipping_phone()
				: (string) $order->get_billing_phone(),
		);
	}

	/**
	 * @param string $reason
	 * @return array
	 */
	private static function blocked( string $reason ): array {
		return array(
			'source'      => self::SOURCE_BLOCKED,
			'province_id' => '',
			'district_id' => '',
			'ward_id'     => '',
			'reason'      => $reason,
		);
	}
}
