<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Cancel Service
 * 
 * Cancels shipments using SuperShip API.
 * 
 * Endpoint: POST /v1/partner/orders/cancel
 * 
 * Response: {code, soc, address, status: 0, status_name: "Hủy"}
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Cancel_Service {
	
	/** @var SuperShip_Http_Client_Interface */
	private $client;

	/**
	 * @param SuperShip_Http_Client_Interface|null $client HTTP client
	 */
	public function __construct( SuperShip_Http_Client_Interface $client = null ) {
		$this->client = $client ?: new SuperShip_HTTP_Client();
	}

	/**
	 * Cancel shipment
	 * 
	 * @param string $tracking_number SuperShip tracking number
	 * @return array {success: bool, cancelled?: array, error?: string}
	 */
	public function cancel( string $tracking_number ): array {
		// Validate input
		if ( '' === $tracking_number ) {
			return array(
				'success' => false,
				'error'   => __( 'Tracking number is required', 'supership-woocommerce' ),
			);
		}
		
		// Call SuperShip API
		$response = $this->client->post(
			'/v1/partner/orders/cancel',
			array( 'code' => $tracking_number )
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
				'error'   => __( 'Invalid cancel response from SuperShip', 'supership-woocommerce' ),
			);
		}
		
		// Transform to standard format
		$cancelled = $this->transform_cancelled( $results );
		
		return array(
			'success'   => true,
			'cancelled' => $cancelled,
		);
	}

	/**
	 * Batch cancel shipments
	 * 
	 * @param array $tracking_numbers Array of tracking numbers
	 * @return array {success: bool, results: array, errors?: array}
	 */
	public function cancel_batch( array $tracking_numbers ): array {
		if ( empty( $tracking_numbers ) ) {
			return array(
				'success' => false,
				'error'   => __( 'At least one tracking number is required', 'supership-woocommerce' ),
			);
		}
		
		$results = array();
		$errors  = array();
		
		foreach ( $tracking_numbers as $tracking_number ) {
			$result = $this->cancel( $tracking_number );
			
			if ( $result['success'] ) {
				$results[] = $result['cancelled'];
			} else {
				$errors[ $tracking_number ] = $result['error'];
			}
		}
		
		return array(
			'success' => empty( $errors ),
			'results' => $results,
			'errors'  => $errors,
		);
	}

	/**
	 * Transform SuperShip cancel response
	 * 
	 * @param array $results SuperShip API results
	 * @return array Transformed cancelled info
	 */
	private function transform_cancelled( array $results ): array {
		return array(
			'tracking_number' => isset( $results['code'] ) ? $results['code'] : '',
			'soc'             => isset( $results['soc'] ) ? $results['soc'] : '',
			'address'         => isset( $results['address'] ) ? $results['address'] : '',
			'status'          => isset( $results['status'] ) ? (int) $results['status'] : 0,
			'status_name'     => isset( $results['status_name'] ) ? $results['status_name'] : '',
		);
	}

	/**
	 * Check if shipment can be cancelled
	 * 
	 * Note: This is a client-side check based on status.
	 * The actual cancellation eligibility is determined by SuperShip API.
	 * 
	 * @param int $status Current shipment status
	 * @return bool
	 */
	public function can_cancel( int $status ): bool {
		// Generally, shipments can be cancelled before pickup or early in transit
		// Status 0 = Cancelled (already cancelled)
		// Status 1 = Chờ Duyệt (Pending approval)
		// Status 2 = Chờ Lấy Hàng (Waiting for pickup)
		// Status 3 = Đang Lấy Hàng (Picking up)
		// Status 12-27 = Delivered/Returned states (too late)
		
		if ( 0 === $status ) {
			return false; // Already cancelled
		}
		
		// Statuses 1-3 are typically cancellable
		if ( $status >= 1 && $status <= 3 ) {
			return true;
		}
		
		// Status 4-11, 23 might be cancellable (depends on SuperShip policy)
		// Let the API decide - return true for now
		if ( $status >= 4 && $status <= 11 ) {
			return true;
		}
		
		if ( 23 === $status ) {
			return true;
		}
		
		// Delivered/returned states: not cancellable
		return false;
	}

	/**
	 * Get cancellation eligibility message
	 * 
	 * @param int $status Current shipment status
	 * @return string Human-readable message
	 */
	public function get_cancel_eligibility_message( int $status ): string {
		if ( $this->can_cancel( $status ) ) {
			return __( 'This shipment can be cancelled', 'supership-woocommerce' );
		}
		
		if ( 0 === $status ) {
			return __( 'This shipment is already cancelled', 'supership-woocommerce' );
		}
		
		// Delivered/returned states
		if ( $status >= 12 && $status <= 22 ) {
			return __( 'This shipment cannot be cancelled (already delivered or returned)', 'supership-woocommerce' );
		}
		
		return __( 'This shipment cannot be cancelled', 'supership-woocommerce' );
	}
}
