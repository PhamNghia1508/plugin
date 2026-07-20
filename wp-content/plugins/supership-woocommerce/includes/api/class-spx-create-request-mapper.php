<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translates a normalised internal shipment into the official batch_create_order
 * "order" object. Pure: no network, no WP, no credentials. Only documented fields
 * are emitted. Create adds sender/deliver name+phone, payment_role and order_id
 * (client order id) compared to the rate request.
 */
final class SPX_Create_Request_Mapper {
	const MAX_CREATE_WEIGHT_KG = 17;
	const MAX_DIM_CM           = 60;
	const MAX_DIM_SUM_CM       = 180;
	const HIGH_VALUE_VND       = 3000000;

	/** @param array $s normalised shipment (see SPX_Shipment_Service::normalise) */
	public static function map_order( array $s ): array {
		$sender = $s['sender'] ?? array();
		$rcpt   = $s['recipient'] ?? array();

		$order = array(
			'order_id'    => (string) ( $s['client_order_id'] ?? '' ),
			'base_info'   => array( 'service_type' => (int) ( $s['service_type'] ?? 1 ) ),
			'sender_info' => self::party( 'sender', $sender ),
			'deliver_info' => self::party( 'deliver', $rcpt ),
		);

		$is_cod  = ! empty( $s['is_cod'] );
		$insured = max( 0, (int) ( $s['insured_value'] ?? 0 ) );
		$ff      = array(
			'payment_role'                     => (int) ( $s['payment_role'] ?? 1 ),
			'cod_collection'                   => $is_cod ? 1 : 0,
			'high_value_processing_collection' => $insured >= self::HIGH_VALUE_VND ? 1 : 0,
			'collect_type'                     => (int) ( $s['collect_type'] ?? 2 ),
		);
		if ( $is_cod ) {
			if ( ! array_key_exists( 'cod_amount', $s ) || ! is_int( $s['cod_amount'] ) || $s['cod_amount'] < 0 ) {
				throw new InvalidArgumentException( 'Canonical COD amount is required.' );
			}
			$ff['cod_amount'] = $s['cod_amount'];
		}
		// Pickup fields only when collect_type = 1 (pickup) and a slot is supplied.
		if ( 1 === (int) $ff['collect_type'] && ! empty( $s['pickup_time'] ) ) {
			$ff['pickup_time'] = (int) $s['pickup_time'];
			if ( ! empty( $s['pickup_time_range_id'] ) ) { $ff['pickup_time_range_id'] = (int) $s['pickup_time_range_id']; }
			if ( ! empty( $s['pickup_time_range'] ) ) { $ff['pickup_time_range'] = (string) $s['pickup_time_range']; }
		}
		$order['fulfillment_info'] = $ff;

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

	private static function party( string $prefix, array $p ): array {
		$out = array(
			$prefix . '_state'    => (string) ( $p['province'] ?? '' ),
			$prefix . '_city'     => (string) ( $p['district'] ?? '' ),
			$prefix . '_district' => (string) ( $p['ward'] ?? '' ),
			$prefix . '_name'     => (string) ( $p['name'] ?? '' ),
			$prefix . '_phone'    => (string) ( $p['phone'] ?? '' ),
			$prefix . '_detail_address' => (string) ( $p['address'] ?? '' ),
		);
		if ( 'deliver' === $prefix ) {
			$note = trim( (string) ( $p['instruction'] ?? '' ) );
			if ( '' !== $note ) { $out['deliver_instruction'] = self::truncate( $note, 256 ); }
		}
		return $out;
	}

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
		return self::truncate( $name, 256 );
	}

	private static function truncate( string $s, int $len ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}
}
