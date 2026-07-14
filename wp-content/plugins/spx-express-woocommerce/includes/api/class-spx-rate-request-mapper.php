<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translates a normalised internal shipment into the official
 * batch_check_order "order" object. Pure: no network, no WP, no credentials.
 * Only documented fields are emitted; optional fields are omitted when absent.
 * batch_check_order has no order_id request field, so none is added.
 */
final class SPX_Rate_Request_Mapper {
	const MAX_RATE_WEIGHT_KG = 15;   // Shipping Fee API limit (not the 17 kg create limit)
	const MAX_DIM_CM         = 60;   // VN per-dimension limit
	const MAX_DIM_SUM_CM     = 180;  // VN L+W+H limit
	const HIGH_VALUE_VND     = 3000000;

	/**
	 * @param array $s normalised shipment (see SPX_Rate_Service::normalise_shipment)
	 * @return array the single "order" object for orders[]
	 */
	public static function map_order( array $s ): array {
		$order = array(
			'base_info'   => array(
				'service_type' => (int) ( $s['service_type'] ?? 1 ),
			),
			'sender_info'  => self::location( 'sender', $s['sender'] ?? array() ),
			'deliver_info' => self::location( 'deliver', $s['recipient'] ?? array() ),
			'fulfillment_info' => array(),
			'parcel_info'  => array(),
		);

		// Fulfillment: COD + high-value processing + collect type.
		$is_cod  = ! empty( $s['is_cod'] );
		$insured = max( 0, (int) ( $s['insured_value'] ?? 0 ) );
		$hv      = $insured >= self::HIGH_VALUE_VND ? 1 : 0;
		$ff      = array(
			'cod_collection'                   => $is_cod ? 1 : 0,
			'high_value_processing_collection' => $hv,
			'collect_type'                     => (int) ( $s['collect_type'] ?? 2 ),
		);
		if ( $is_cod ) { $ff['cod_amount'] = max( 0, (int) ( $s['cod_amount'] ?? 0 ) ); }
		$order['fulfillment_info'] = $ff;

		// Parcel: weight (kg), item name/qty, optional insured value + dimensions.
		$weight_kg = round( max( 0, (int) ( $s['weight_grams'] ?? 0 ) ) / 1000, 3 );
		$parcel    = array(
			'parcel_weight'        => $weight_kg,
			'parcel_item_name'     => self::item_name( (string) ( $s['parcel_item_name'] ?? '' ) ),
			'parcel_item_quantity' => max( 1, (int) ( $s['parcel_item_quantity'] ?? 1 ) ),
		);
		if ( $insured > 0 ) { $parcel['express_insured_value'] = $insured; }
		$dims = self::dimensions( $s );
		if ( null !== $dims ) { $parcel += $dims; }
		$order['parcel_info'] = $parcel;

		return $order;
	}

	private static function location( string $prefix, array $loc ): array {
		$out = array(
			$prefix . '_state'    => (string) ( $loc['province'] ?? '' ),
			$prefix . '_city'     => (string) ( $loc['district'] ?? '' ),
			$prefix . '_district' => (string) ( $loc['ward'] ?? '' ),
		);
		$addr = trim( (string) ( $loc['address'] ?? '' ) );
		if ( '' !== $addr ) { $out[ $prefix . '_detail_address' ] = $addr; }
		return $out;
	}

	/** Include dimensions only when all three are valid and within limits; else omit all. */
	private static function dimensions( array $s ): ?array {
		$l = isset( $s['length_cm'] ) ? (float) $s['length_cm'] : 0;
		$w = isset( $s['width_cm'] ) ? (float) $s['width_cm'] : 0;
		$h = isset( $s['height_cm'] ) ? (float) $s['height_cm'] : 0;
		if ( $l <= 0 || $w <= 0 || $h <= 0 ) { return null; }
		if ( $l > self::MAX_DIM_CM || $w > self::MAX_DIM_CM || $h > self::MAX_DIM_CM ) { return null; }
		if ( ( $l + $w + $h ) > self::MAX_DIM_SUM_CM ) { return null; }
		return array( 'parcel_length' => $l, 'parcel_width' => $w, 'parcel_height' => $h );
	}

	private static function item_name( string $name ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		if ( '' === $name ) { $name = 'Hàng hóa'; }
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 256 ) : substr( $name, 0, 256 );
	}
}
