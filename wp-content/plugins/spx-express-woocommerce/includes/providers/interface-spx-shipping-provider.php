<?php
defined( 'ABSPATH' ) || exit;

interface SPX_Shipping_Provider_Interface {
	public function calculate_rate( array $shipment ): array;
	public function create_shipment( array $shipment ): array;
	public function cancel_shipment( string $tracking_number ): array;
	public function get_tracking( string $tracking_number ): array;
	public function get_label( string $tracking_number ): array;
}

