<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SPX_Parcel_Builder' ) ) {
	require_once dirname( __DIR__ ) . '/parcel/class-spx-parcel-builder.php';
}

/** Builds the existing internal SPX shipment contract from a checkout package. */
final class SPX_Checkout_Rate_Request_Builder {
	/** @var callable|null */ private $validator;

	public function __construct( callable $validator = null ) { $this->validator = $validator; }

	public function build( array $package, array $selection, array $sender, array $context = array() ): array {
		$is_cod = ! empty( $context['is_cod'] );
		$validated = $this->validate_selection( $selection, $is_cod );
		if ( empty( $validated['valid'] ) ) { return $this->failure( (string) ( $validated['error'] ?? 'address_invalid' ) ); }
		if ( ! $this->valid_sender( $sender ) ) { return $this->failure( 'sender_invalid' ); }

		// Collect item names and canonical parcel lines. Geometry (weight/dimensions,
		// unit conversion, variation fallback, deterministic multi-item policy, SPX
		// limits) is delegated to SPX_Parcel_Builder — the SAME service Create uses.
		$items          = array();
		$parcel_lines   = array();
		$dimension_unit = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_dimension_unit', 'cm' ) : 'cm';
		if ( '' === $dimension_unit ) { $dimension_unit = 'cm'; }
		foreach ( isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array() as $line ) {
			$product = isset( $line['data'] ) && is_object( $line['data'] ) ? $line['data'] : null;
			if ( ! $product || ( method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) || ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) || ( method_exists( $product, 'is_downloadable' ) && $product->is_downloadable() ) ) { continue; }
			$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$weight = method_exists( $product, 'get_weight' ) ? $product->get_weight() : '';
			$dimension_product = $product;
			if ( '' === (string) $weight && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) { $weight = $parent->get_weight(); $dimension_product = $parent; }
			}
			$grams = '' === (string) $weight ? null : (int) round( wc_get_weight( max( 0, (float) $weight ), 'g' ) );
			$name  = isset( $line['name'] ) ? (string) $line['name'] : ( method_exists( $product, 'get_name' ) ? (string) $product->get_name() : 'Test item' );
			$items[] = array( 'name' => sanitize_text_field( $name ), 'quantity' => $quantity );
			$parcel_lines[] = array(
				'weight'         => $grams,
				'weight_unit'    => 'g',
				'length'         => method_exists( $dimension_product, 'get_length' ) ? $dimension_product->get_length() : '',
				'width'          => method_exists( $dimension_product, 'get_width' ) ? $dimension_product->get_width() : '',
				'height'         => method_exists( $dimension_product, 'get_height' ) ? $dimension_product->get_height() : '',
				'dimension_unit' => $dimension_unit,
				'quantity'       => $quantity,
				'virtual'        => false,
			);
		}
		if ( empty( $items ) ) { return $this->failure( 'no_shippable_items' ); }

		$parcel_policy = isset( $context['parcel_policy'] ) ? (string) $context['parcel_policy'] : SPX_Parcel_Builder::configured_policy();
		$parcel        = SPX_Parcel_Builder::from_lines(
			$parcel_lines,
			array(
				'max_weight_kg'      => 15.0, // SPX VN fee-check weight limit.
				'policy'             => $parcel_policy,
				'require_dimensions' => SPX_Parcel_Builder::POLICY_FAIL_CLOSED === $parcel_policy,
			) + SPX_Parcel_Builder::configured_defaults()
		);
		if ( ! $parcel->is_valid() ) {
			$codes = $parcel->error_codes();
			return $this->failure( $codes ? (string) $codes[0] : 'parcel_invalid' );
		}

		$snapshot = $validated['snapshot'];
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$recipient_name = trim( (string) ( $context['recipient_name'] ?? trim( (string) ( $destination['first_name'] ?? '' ) . ' ' . (string) ( $destination['last_name'] ?? '' ) ) ) );
		$recipient_phone = (string) ( $context['recipient_phone'] ?? ( $destination['phone'] ?? '' ) );
		$recipient_address = trim( (string) ( $context['recipient_address'] ?? ( $destination['address'] ?? ( $destination['address_1'] ?? '' ) ) ) );
		$declared = max( 0, (int) round( (float) ( $context['declared_value'] ?? ( $package['contents_cost'] ?? 0 ) ) ) );
		$shipment = array(
			'sender' => array(
				'name'=>(string)$sender['sender_name'], 'phone'=>(string)$sender['sender_phone'], 'address'=>(string)$sender['sender_detail_address'],
				'province'=>(string)($sender['sender_province_name']??''), 'district'=>(string)($sender['sender_district_name']??''), 'ward'=>(string)($sender['sender_ward_name']??''),
				'province_code'=>(string)$sender['sender_province_code'], 'district_code'=>(string)$sender['sender_district_code'], 'ward_code'=>(string)$sender['sender_ward_code'],
			),
			'recipient' => array(
				'name'=>$recipient_name, 'phone'=>$recipient_phone, 'address'=>$recipient_address,
				'province'=>(string)$snapshot['province_name'], 'district'=>(string)$snapshot['district_name'], 'ward'=>(string)$snapshot['ward_name'],
				'province_code'=>(string)$snapshot['province_id'], 'district_code'=>(string)$snapshot['district_id'], 'ward_code'=>(string)$snapshot['ward_id'],
			),
			'items'=>$items, 'weight_grams'=>(int) round( $parcel->weight_kg() * 1000 ), 'declared_value'=>$declared, 'is_cod'=>$is_cod,
			'cod_amount'=>$is_cod ? max( 0, (int) round( (float) ( $context['cod_amount'] ?? $declared ) ) ) : 0,
			'service_type'=>(int)($sender['service_type']??1), 'collect_type'=>2,
		);
		if ( $parcel->has_dimensions() ) { $shipment += array( 'length_cm'=>$parcel->length_cm(), 'width_cm'=>$parcel->width_cm(), 'height_cm'=>$parcel->height_cm() ); }
		return array( 'success'=>true, 'shipment'=>$shipment, 'snapshot'=>$snapshot, 'parcel'=>$parcel->to_snapshot() );
	}

	private function validate_selection( array $selection, bool $is_cod ): array {
		if ( $this->validator ) { return call_user_func( $this->validator, $selection, $is_cod ); }
		$service = new SPX_Checkout_Address_Service();
		return $service->validate_selection( (string)($selection['province_id']??''), (string)($selection['district_id']??''), (string)($selection['ward_id']??''), $is_cod, (string)($selection['dataset_version']??'') );
	}

	private function valid_sender( array $sender ): bool {
		foreach ( array('sender_name','sender_phone','sender_detail_address','sender_province_code','sender_district_code','sender_ward_code') as $key ) { if ( '' === trim( (string)($sender[$key]??'') ) ) { return false; } }
		if ( $this->validator ) { return true; }
		$repo = new SPX_Address_Repository();
		if ( ! $repo->validate_hierarchy( (string)$sender['sender_province_code'], (string)$sender['sender_district_code'], (string)$sender['sender_ward_code'] ) ) { return false; }
		$ward = $repo->get_ward( (string)$sender['sender_district_code'], (string)$sender['sender_ward_code'] );
		return is_array( $ward ) && 'available' === strtolower( trim( (string)($ward['status']??'') ) );
	}

	private function failure( string $code ): array { return array( 'success'=>false, 'error_code'=>sanitize_key( $code ) ); }
}

