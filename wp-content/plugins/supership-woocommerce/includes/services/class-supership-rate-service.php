<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Rate Service
 * 
 * Calculates shipping rates using SuperShip API.
 * 
 * Endpoint: GET /v1/partner/orders/price
 * Required params: sender_province, sender_district, receiver_province, receiver_district, weight
 * Optional params: value (for insurance calculation)
 * 
 * Response: [{service, fee, insurance, pickup{name}, delivery{name}}, ...]
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Rate_Service {
	
	/** @var SuperShip_Http_Client_Interface */
	private $client;
	
	/** @var SuperShip_Address_Repository */
	private $address_repo;

	/**
	 * @param SuperShip_Http_Client_Interface|null $client HTTP client
	 * @param SuperShip_Address_Repository|null    $address_repo Address repository
	 */
	public function __construct(
		SuperShip_Http_Client_Interface $client = null,
		SuperShip_Address_Repository $address_repo = null
	) {
		$this->client       = $client ?: new SuperShip_HTTP_Client();
		$this->address_repo = $address_repo ?: new SuperShip_Address_Repository();
	}

	/**
	 * Calculate shipping rate
	 * 
	 * @param array $params Rate calculation parameters
	 * @return array {success: bool, rates?: array, error?: string}
	 */
	public function calculate( array $params ): array {
		// Validate required parameters
		$validation = $this->validate_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}
		
		// Resolve address names (SuperShip needs exact canonical names)
		$resolved = $this->resolve_addresses( $params );
		if ( ! $resolved['success'] ) {
			return $resolved;
		}
		
		// Build request params
		$query_params = array(
			'sender_province'   => $resolved['sender_province'],
			'sender_district'   => $resolved['sender_district'],
			'receiver_province' => $resolved['receiver_province'],
			'receiver_district' => $resolved['receiver_district'],
			'weight'            => (int) $params['weight_grams'],
		);
		
		// Add value for insurance calculation (optional)
		if ( isset( $params['declared_value'] ) && $params['declared_value'] > 0 ) {
			$query_params['value'] = (int) $params['declared_value'];
		}
		
		// Call SuperShip API
		$response = $this->client->get( '/v1/partner/orders/price', $query_params );
		
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
				'error'   => __( 'SuperShip trả về dữ liệu không đọc được khi tính phí', 'supership-woocommerce' ),
			);
		}
		
		// Transform to standard format
		$rates = $this->transform_rates( $results );
		
		return array(
			'success' => true,
			'rates'   => $rates,
		);
	}

	/**
	 * Validate input parameters
	 * 
	 * @param array $params Parameters to validate
	 * @return array {valid: bool, error?: string}
	 */
	private function validate_params( array $params ): array {
		$required = array(
			'sender_province'   => __( 'Thiếu Tỉnh/Thành của kho gửi', 'supership-woocommerce' ),
			'sender_district'   => __( 'Thiếu Quận/Huyện của kho gửi', 'supership-woocommerce' ),
			'receiver_province' => __( 'Thiếu Tỉnh/Thành của người nhận', 'supership-woocommerce' ),
			'receiver_district' => __( 'Thiếu Quận/Huyện của người nhận', 'supership-woocommerce' ),
			'weight_grams'      => __( 'Thiếu cân nặng gói hàng', 'supership-woocommerce' ),
		);
		
		foreach ( $required as $key => $message ) {
			if ( empty( $params[ $key ] ) ) {
				return array(
					'valid' => false,
					'error' => $message,
				);
			}
		}
		
		// Validate weight
		$weight = (int) $params['weight_grams'];
		if ( $weight <= 0 ) {
			return array(
				'valid' => false,
				'error' => __( 'Cân nặng phải lớn hơn 0', 'supership-woocommerce' ),
			);
		}
		
		if ( $weight > 30000 ) { // 30kg limit (adjust if SuperShip has different limit)
			return array(
				'valid' => false,
				'error' => __( 'Gói hàng vượt giới hạn 30kg', 'supership-woocommerce' ),
			);
		}
		
		return array( 'valid' => true );
	}

	/**
	 * Resolve address names to SuperShip canonical names
	 * 
	 * @param array $params Input parameters
	 * @return array {success: bool, sender_province?, sender_district?, receiver_province?, receiver_district?, error?}
	 */
	private function resolve_addresses( array $params ): array {
		// Resolve sender province
		$sender_province_match = $this->address_repo->find_province_by_name( $params['sender_province'] );
		if ( 'matched' !== $sender_province_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Tỉnh/Thành kho gửi "%s" trong danh mục SuperShip', 'supership-woocommerce' ),
					$params['sender_province']
				),
			);
		}
		
		// Resolve sender district
		$sender_district_match = $this->address_repo->find_district_by_name(
			$sender_province_match['code'],
			$params['sender_district']
		);
		if ( 'matched' !== $sender_district_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Quận/Huyện kho gửi "%s" trong danh mục SuperShip', 'supership-woocommerce' ),
					$params['sender_district']
				),
			);
		}
		
		// Resolve receiver province
		$receiver_province_match = $this->address_repo->find_province_by_name( $params['receiver_province'] );
		if ( 'matched' !== $receiver_province_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Tỉnh/Thành "%s" trong danh mục SuperShip', 'supership-woocommerce' ),
					$params['receiver_province']
				),
			);
		}
		
		// Resolve receiver district
		$receiver_district_match = $this->address_repo->find_district_by_name(
			$receiver_province_match['code'],
			$params['receiver_district']
		);
		if ( 'matched' !== $receiver_district_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Quận/Huyện "%s" trong danh mục SuperShip', 'supership-woocommerce' ),
					$params['receiver_district']
				),
			);
		}
		
		return array(
			'success'           => true,
			'sender_province'   => $sender_province_match['name'],
			'sender_district'   => $sender_district_match['name'],
			'receiver_province' => $receiver_province_match['name'],
			'receiver_district' => $receiver_district_match['name'],
		);
	}

	/**
	 * Transform SuperShip rates to standard format
	 * 
	 * @param array $results SuperShip API results
	 * @return array Transformed rates
	 */
	private function transform_rates( array $results ): array {
		$rates = array();
		
		foreach ( $results as $item ) {
			if ( ! isset( $item['service'], $item['fee'] ) ) {
				continue;
			}
			
			$rates[] = array(
				'service_code' => $this->normalize_service_code( $item['service'] ),
				'service_name' => (string) $item['service'],
				'fee'          => (int) $item['fee'],
				'insurance'    => isset( $item['insurance'] ) ? (int) $item['insurance'] : 0,
				'total'        => ( isset( $item['fee'] ) ? (int) $item['fee'] : 0 )
				                  + ( isset( $item['insurance'] ) ? (int) $item['insurance'] : 0 ),
				'pickup_name'  => isset( $item['pickup']['name'] ) ? $item['pickup']['name'] : '',
				'delivery_name' => isset( $item['delivery']['name'] ) ? $item['delivery']['name'] : '',
			);
		}
		
		return $rates;
	}

	/**
	 * Normalize service name to code
	 * 
	 * @param string $service_name Service name from SuperShip
	 * @return string Service code
	 */
	private function normalize_service_code( string $service_name ): string {
		// Map Vietnamese service names to codes
		$map = array(
			'Tốc Hành' => 'express',
			'Tiêu Chuẩn' => 'standard',
		);
		
		return isset( $map[ $service_name ] ) ? $map[ $service_name ] : sanitize_key( $service_name );
	}
}
