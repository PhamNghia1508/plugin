<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Mock_Provider implements SPX_Shipping_Provider_Interface {
	public function calculate_rate( array $shipment ): array {
		return array( 'success' => true, 'cost' => 0, 'currency' => get_woocommerce_currency() );
	}

	public function create_shipment( array $shipment ): array {
		if ( empty( $shipment['recipient']['address'] ) || empty( $shipment['recipient']['phone'] ) || empty( $shipment['items'] ) || empty( $shipment['weight_grams'] ) ) {
			return array( 'success' => false, 'message' => __( 'Shipment data is incomplete.', 'spx-express-woocommerce' ) );
		}
		$tracking = 'SPXMOCK-' . gmdate( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
		return array(
			'success'         => true,
			'tracking_number' => $tracking,
			'status'          => 'created',
			'created_at'      => current_time( 'mysql', true ),
			'provider'        => 'mock',
		);
	}

	public function cancel_shipment( string $tracking_number ): array {
		return array( 'success' => true, 'tracking_number' => sanitize_text_field( $tracking_number ), 'status' => 'cancelled' );
	}

	public function get_tracking( string $tracking_number ): array {
		return array( 'success' => true, 'tracking_number' => sanitize_text_field( $tracking_number ), 'status' => 'created' );
	}

	public function get_label( string $tracking_number ): array {
		return array( 'success' => false, 'message' => __( 'Mock mode does not generate shipping labels.', 'spx-express-woocommerce' ) );
	}
}

