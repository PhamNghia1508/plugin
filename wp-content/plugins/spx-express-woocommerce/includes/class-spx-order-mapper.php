<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Order_Mapper {
	public static function build( WC_Order $order, array $settings = array() ): array {
		$payment        = SPX_Payment_Resolver::resolve( $order );
		$default_grams   = max( 1, absint( $settings['default_item_weight_grams'] ?? 500 ) );
		$dimension_unit  = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_dimension_unit', 'cm' ) : 'cm';
		$items           = array();
		$parcel_lines    = array();
		$total_grams     = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product  = $item->get_product();
			$quantity = max( 1, absint( $item->get_quantity() ) );
			$grams    = $default_grams;
			$weight   = $product ? $product->get_weight() : '';
			if ( $product && '' === $weight && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$weight = $parent ? $parent->get_weight() : '';
			}
			if ( '' !== $weight ) {
				$converted = wc_get_weight( max( 0, (float) $weight ), 'g' );
				$grams     = $converted > 0 ? max( 1, (int) round( $converted ) ) : $default_grams;
			}
			$items[] = array(
				'product_id'   => $product ? $product->get_id() : 0,
				'name'         => sanitize_text_field( $item->get_name() ),
				'quantity'     => $quantity,
				'unit_price'   => max( 0.0, (float) $order->get_item_total( $item, false, false ) ),
				'weight_grams' => $grams,
			);
			$total_grams += $grams * $quantity;

			// Canonical parcel line (geometry only; virtual/downloadable excluded).
			$is_virtual  = $product && ( ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) || ( method_exists( $product, 'is_downloadable' ) && $product->is_downloadable() ) );
			$dim_product = $product;
			if ( $product && $product->is_type( 'variation' ) && $product->get_parent_id() && '' === (string) $product->get_length() ) {
				$parent_dim = wc_get_product( $product->get_parent_id() );
				if ( $parent_dim ) { $dim_product = $parent_dim; }
			}
			$parcel_lines[] = array(
				'weight'         => $grams,
				'weight_unit'    => 'g',
				'length'         => $dim_product ? $dim_product->get_length() : '',
				'width'          => $dim_product ? $dim_product->get_width() : '',
				'height'         => $dim_product ? $dim_product->get_height() : '',
				'dimension_unit' => $dimension_unit,
				'quantity'       => $quantity,
				'virtual'        => (bool) $is_virtual,
			);
		}

		// One canonical parcel object, shared formula with the checkout rate path.
		// require_dimensions=false keeps dimensions optional (SPX contract); they are
		// attached only when present so the wire mapper never emits length/width/height=0.
		$parcel_policy = SPX_Parcel_Builder::configured_policy();
		$parcel        = SPX_Parcel_Builder::from_lines(
			$parcel_lines,
			array(
				'max_weight_kg'      => 17.0, // SPX VN create limit; see SPX_Create_Request_Mapper::MAX_CREATE_WEIGHT_KG.
				'policy'             => $parcel_policy,
				'require_dimensions' => SPX_Parcel_Builder::POLICY_FAIL_CLOSED === $parcel_policy,
			) + SPX_Parcel_Builder::configured_defaults()
		);

		$shipping_address = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
		if ( '' === $shipping_address ) { $shipping_address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ); }
		return array(
			'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(),
			'sender' => array( 'name' => get_bloginfo( 'name' ), 'phone' => '', 'address' => WC()->countries->get_base_address(), 'province' => WC()->countries->get_base_state(), 'district' => '', 'ward' => '' ),
			'recipient' => array(
				'name' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'phone' => sanitize_text_field( $order->get_billing_phone() ), 'address' => sanitize_text_field( $shipping_address ),
				'province' => sanitize_text_field( $order->get_shipping_state() ?: $order->get_billing_state() ), 'district' => sanitize_text_field( $order->get_shipping_city() ?: $order->get_billing_city() ), 'ward' => '',
			),
			// Weight from the canonical parcel (virtual/downloadable excluded), same as
			// the checkout rate path; fall back to the legacy total only if the parcel
			// produced no positive weight.
			'items' => $items,
			'weight_grams' => $parcel->weight_kg() > 0 ? (int) round( $parcel->weight_kg() * 1000 ) : max( 0, $total_grams ),
			'payment' => $payment,
			'payment_method' => $payment['payment_method'],
			'is_paid' => $payment['is_paid'],
			'payment_state' => $payment['payment_state'],
			'is_cod' => 'cash_on_delivery' === $payment['payment_state'],
			'cod_amount' => $payment['cod_amount'],
			'ready_for_shipment' => $payment['ready_for_shipment'],
			'payment_block_reason' => $payment['reason'],
			'declared_value' => max( 0.0, (float) $order->get_total() ), 'note' => sanitize_textarea_field( $order->get_customer_note() ),
			'parcel' => $parcel->to_snapshot(), 'parcel_source' => $parcel->source(),
			'parcel_policy' => $parcel_policy, 'parcel_valid' => $parcel->is_valid(), 'parcel_errors' => $parcel->errors(),
		) + ( $parcel->has_dimensions() ? array( 'length_cm' => $parcel->length_cm(), 'width_cm' => $parcel->width_cm(), 'height_cm' => $parcel->height_cm() ) : array() );
	}

	/**
	 * Enrich an internal payload with the validated sender profile and the SPX
	 * recipient resolution (province/district/ward names + our dataset codes).
	 * Pure: no network, no dataset sync, no order-meta writes. Keeps the internal
	 * shape (NOT SPX wire field names); the API mapper translates later.
	 */
	public static function enrich_for_spx( array $payload, array $sender_profile, array $recipient_resolution ): array {
		$sp = $sender_profile;
		if ( ! empty( $sp ) ) {
			$payload['sender'] = array_merge(
				isset( $payload['sender'] ) && is_array( $payload['sender'] ) ? $payload['sender'] : array(),
				array(
					'name'          => (string) ( $sp['sender_name'] ?? '' ),
					'phone'         => (string) ( $sp['sender_phone'] ?? '' ),
					'address'       => (string) ( $sp['sender_detail_address'] ?? '' ),
					'province'      => (string) ( $sp['sender_province_name'] ?? '' ),
					'district'      => (string) ( $sp['sender_district_name'] ?? '' ),
					'ward'          => (string) ( $sp['sender_ward_name'] ?? '' ),
					'province_code' => (string) ( $sp['sender_province_code'] ?? '' ),
					'district_code' => (string) ( $sp['sender_district_code'] ?? '' ),
					'ward_code'     => (string) ( $sp['sender_ward_code'] ?? '' ),
				)
			);
		}
		$r = $recipient_resolution;
		if ( ! empty( $r ) ) {
			$payload['recipient'] = array_merge(
				isset( $payload['recipient'] ) && is_array( $payload['recipient'] ) ? $payload['recipient'] : array(),
				array_filter( array(
					'province'      => (string) ( $r['province_name'] ?? '' ),
					'district'      => (string) ( $r['district_name'] ?? '' ),
					'ward'          => (string) ( $r['ward_name'] ?? '' ),
				), function ( $v ) { return '' !== $v; } ),
				array(
					'province_code' => (string) ( $r['province_code'] ?? '' ),
					'district_code' => (string) ( $r['district_code'] ?? '' ),
					'ward_code'     => (string) ( $r['ward_code'] ?? '' ),
				)
			);
		}
		return $payload;
	}
}
