<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Tracking Service
 * 
 * Retrieves shipment tracking information.
 * 
 * Endpoint: GET /v1/partner/orders/info
 * 
 * Response includes:
 * - Current status
 * - Journeys (timeline)
 * - Receiver info
 * - Fees breakdown
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Tracking_Service {
	
	/** @var SuperShip_Http_Client_Interface */
	private $client;

	/**
	 * @param SuperShip_Http_Client_Interface|null $client HTTP client
	 */
	public function __construct( SuperShip_Http_Client_Interface $client = null ) {
		$this->client = $client ?: new SuperShip_HTTP_Client();
	}

	/**
	 * Get tracking information
	 * 
	 * @param string $tracking_number SuperShip tracking number or soc
	 * @param string $type Type of tracking number: '1' = SuperShip code (default), '2' = soc
	 * @return array {success: bool, tracking?: array, error?: string}
	 */
	public function get_tracking( string $tracking_number, string $type = '1' ): array {
		if ( '' === $tracking_number ) {
			return array(
				'success' => false,
				'error'   => __( 'Thiếu mã vận đơn', 'supership-woocommerce' ),
			);
		}
		
		// Call SuperShip API
		$response = $this->client->get(
			'/v1/partner/orders/info',
			array(
				'code' => $tracking_number,
				'type' => $type,
			)
		);
		
		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}
		
		// Parse response
		$results = $response->get_results();
		if ( ! is_array( $results ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip trả về dữ liệu không đọc được khi tra cứu vận đơn', 'supership-woocommerce' ),
			);
		}
		
		// Transform to standard format
		$tracking = $this->transform_tracking( $results );
		
		return array(
			'success'  => true,
			'tracking' => $tracking,
		);
	}

	/**
	 * Transform SuperShip tracking response
	 * 
	 * @param array $results SuperShip API results
	 * @return array Transformed tracking info
	 */
	private function transform_tracking( array $results ): array {
		return array(
			// Basic info
			'tracking_number' => isset( $results['code'] ) ? $results['code'] : '',
			'soc'             => isset( $results['soc'] ) ? $results['soc'] : '',
			
			// Status
			'status'          => isset( $results['status'] ) ? (int) $results['status'] : 0,
			'status_name'     => isset( $results['status_name'] ) ? $results['status_name'] : '',
			
			// Receiver
			'receiver'        => $this->transform_receiver( $results ),
			
			// Financial
			'cod_amount'      => isset( $results['amount'] ) ? (int) $results['amount'] : 0,
			'declared_value'  => isset( $results['value'] ) ? (int) $results['value'] : 0,
			'weight'          => isset( $results['weight'] ) ? (int) $results['weight'] : 0,
			'fees'            => $this->transform_fees( $results ),
			
			// Config
			'payer'           => isset( $results['payer'] ) ? $results['payer'] : '',
			'config'          => isset( $results['config'] ) ? $results['config'] : '',
			
			// Timeline (journeys)
			'journeys'        => $this->transform_journeys( $results ),
			
			// Notes & logs
			'notes'           => isset( $results['notes'] ) && is_array( $results['notes'] ) ? $results['notes'] : array(),
			'calllogs'        => isset( $results['calllogs'] ) && is_array( $results['calllogs'] ) ? $results['calllogs'] : array(),
			
			// Last update
			'last_sman'       => isset( $results['last_sman'] ) ? $results['last_sman'] : '',
			'created_at'      => isset( $results['created_at'] ) ? $results['created_at'] : '',
			'updated_at'      => isset( $results['updated_at'] ) ? $results['updated_at'] : '',
		);
	}

	/**
	 * Transform receiver info
	 * 
	 * @param array $results SuperShip results
	 * @return array Receiver info
	 */
	private function transform_receiver( array $results ): array {
		if ( ! isset( $results['receiver'] ) || ! is_array( $results['receiver'] ) ) {
			return array(
				'name'              => '',
				'phone'             => '',
				'address'           => '',
				'formatted_address' => '',
			);
		}
		
		$receiver = $results['receiver'];
		
		return array(
			'name'              => isset( $receiver['name'] ) ? $receiver['name'] : '',
			'phone'             => isset( $receiver['phone'] ) ? $receiver['phone'] : '',
			'address'           => isset( $receiver['address'] ) ? $receiver['address'] : '',
			'formatted_address' => isset( $receiver['formatted_address'] ) ? $receiver['formatted_address'] : '',
		);
	}

	/**
	 * Transform fees breakdown
	 * 
	 * @param array $results SuperShip results
	 * @return array Fees breakdown
	 */
	private function transform_fees( array $results ): array {
		if ( ! isset( $results['fee'] ) || ! is_array( $results['fee'] ) ) {
			return array(
				'shipment'  => 0,
				'insurance' => 0,
				'return'    => 0,
				'barter'    => 0,
				'address'   => 0,
			);
		}
		
		$fee = $results['fee'];
		
		return array(
			'shipment'  => isset( $fee['shipment'] ) ? (int) $fee['shipment'] : 0,
			'insurance' => isset( $fee['insurance'] ) ? (int) $fee['insurance'] : 0,
			'return'    => isset( $fee['return'] ) ? (int) $fee['return'] : 0,
			'barter'    => isset( $fee['barter'] ) ? (int) $fee['barter'] : 0,
			'address'   => isset( $fee['address'] ) ? (int) $fee['address'] : 0,
		);
	}

	/**
	 * Transform journeys (timeline)
	 * 
	 * @param array $results SuperShip results
	 * @return array Journeys timeline
	 */
	private function transform_journeys( array $results ): array {
		if ( ! isset( $results['journeys'] ) || ! is_array( $results['journeys'] ) ) {
			return array();
		}
		
		$journeys = array();
		
		foreach ( $results['journeys'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			
			$journeys[] = array(
				'time'     => isset( $item['time'] ) ? $item['time'] : '',
				'status'   => isset( $item['status'] ) ? $item['status'] : '',
				'province' => isset( $item['province'] ) ? $item['province'] : '',
				'district' => isset( $item['district'] ) ? $item['district'] : '',
				'note'     => isset( $item['note'] ) ? $item['note'] : '',
			);
		}
		
		return $journeys;
	}

	/**
	 * Get latest journey status
	 * 
	 * @param array $tracking Tracking info from get_tracking()
	 * @return array|null Latest journey or null
	 */
	public function get_latest_journey( array $tracking ): ?array {
		if ( empty( $tracking['journeys'] ) || ! is_array( $tracking['journeys'] ) ) {
			return null;
		}
		
		// Journeys are typically ordered newest first
		return reset( $tracking['journeys'] ) ?: null;
	}

	/**
	 * Check if shipment is delivered
	 * 
	 * @param array $tracking Tracking info
	 * @return bool
	 */
	public function is_delivered( array $tracking ): bool {
		$status = isset( $tracking['status'] ) ? (int) $tracking['status'] : 0;
		
		// Status 12 = Đã Giao Hàng Toàn Bộ
		// Status 13 = Đã Giao Hàng Một Phần
		return 12 === $status || 13 === $status;
	}

	/**
	 * Check if shipment is cancelled
	 * 
	 * @param array $tracking Tracking info
	 * @return bool
	 */
	public function is_cancelled( array $tracking ): bool {
		$status = isset( $tracking['status'] ) ? (int) $tracking['status'] : 0;
		
		// Status 0 = Huỷ
		return 0 === $status;
	}

	/**
	 * Check if shipment is in transit
	 * 
	 * @param array $tracking Tracking info
	 * @return bool
	 */
	public function is_in_transit( array $tracking ): bool {
		$status = isset( $tracking['status'] ) ? (int) $tracking['status'] : 0;
		
		// Statuses 3-11, 23 are various in-transit states
		$transit_statuses = array( 3, 4, 7, 8, 9, 10, 11, 23 );
		
		return in_array( $status, $transit_statuses, true );
	}
}
