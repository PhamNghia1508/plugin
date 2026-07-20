<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Shipment Service
 * 
 * Creates shipments using SuperShip API.
 * 
 * Endpoint: POST /v1/partner/orders/add
 * 
 * Key features:
 * - Idempotency via soc (Sender's Order Code) field
 * - Commune-level addressing (required by SuperShip)
 * - Support for both warehouse pickup_code and manual pickup address
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Shipment_Service {
	
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
	 * Create shipment
	 * 
	 * @param array $params Shipment parameters
	 * @return array {success: bool, shipment?: array, error?: string, duplicate?: bool}
	 */
	public function create( array $params ): array {
		// Validate required parameters
		$validation = $this->validate_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}
		
		// Resolve addresses to canonical names
		$resolved = $this->resolve_addresses( $params );
		if ( ! $resolved['success'] ) {
			return $resolved;
		}
		
		// Build request body
		$body = $this->build_request_body( $params, $resolved );
		
		// Call SuperShip API
		$response = $this->client->post( '/v1/partner/orders/add', $body );
		
		if ( ! $response->is_success() ) {
			$error_message = SuperShip_API_Error_Mapper::get_safe_message( $response );
			
			// Check for duplicate shipment error
			$is_duplicate = $this->is_duplicate_error( $response );
			
			return array(
				'success'   => false,
				'error'     => $error_message,
				'duplicate' => $is_duplicate,
			);
		}
		
		// Parse response
		$results = $response->get_results();
		if ( ! is_array( $results ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip trả về dữ liệu không đọc được khi tạo vận đơn', 'supership-woocommerce' ),
			);
		}
		
		// Transform to standard format
		$shipment = $this->transform_shipment( $results );
		
		return array(
			'success'  => true,
			'shipment' => $shipment,
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
			// Receiver (required)
			'receiver_name'     => __( 'Thiếu tên người nhận', 'supership-woocommerce' ),
			'receiver_phone'    => __( 'Thiếu số điện thoại người nhận', 'supership-woocommerce' ),
			'receiver_address'  => __( 'Thiếu địa chỉ người nhận', 'supership-woocommerce' ),
			'receiver_province' => __( 'Thiếu Tỉnh/Thành của người nhận', 'supership-woocommerce' ),
			'receiver_district' => __( 'Thiếu Quận/Huyện của người nhận', 'supership-woocommerce' ),
			'receiver_commune'  => __( 'Thiếu Phường/Xã của người nhận', 'supership-woocommerce' ),
			
			// Package details
			'weight_grams'      => __( 'Thiếu cân nặng gói hàng', 'supership-woocommerce' ),
			'declared_value'    => __( 'Thiếu giá trị khai báo đơn hàng', 'supership-woocommerce' ),
			'cod_amount'        => __( 'Thiếu số tiền thu hộ (COD)', 'supership-woocommerce' ),
			
			// Service config
			'service'           => __( 'Chưa chọn dịch vụ giao hàng (xem tab Vận chuyển trong Cài đặt SuperShip)', 'supership-woocommerce' ),
			'config'            => __( 'Chưa chọn tuỳ chọn giao hàng (xem tab Vận chuyển trong Cài đặt SuperShip)', 'supership-woocommerce' ),
			'payer'             => __( 'Chưa chọn bên trả phí ship (xem tab Vận chuyển trong Cài đặt SuperShip)', 'supership-woocommerce' ),
			'product_type'      => __( 'Thiếu loại hàng hoá', 'supership-woocommerce' ),
			
			// Idempotency
			'soc'               => __( 'Thiếu mã đơn của shop', 'supership-woocommerce' ),
		);
		
		foreach ( $required as $key => $message ) {
			if ( ! isset( $params[ $key ] ) || '' === $params[ $key ] ) {
				return array(
					'valid' => false,
					'error' => $message,
				);
			}
		}
		
		// Validate pickup (either pickup_code OR manual address)
		$has_pickup_code = ! empty( $params['pickup_code'] );
		$has_manual_pickup = ! empty( $params['pickup_phone'] )
		                      && ! empty( $params['pickup_address'] )
		                      && ! empty( $params['pickup_province'] )
		                      && ! empty( $params['pickup_district'] )
		                      && ! empty( $params['pickup_commune'] );
		
		if ( ! $has_pickup_code && ! $has_manual_pickup ) {
			return array(
				'valid' => false,
				'error' => __( 'Chưa chọn kho lấy hàng - vào Cài đặt SuperShip, tab "Kho lấy hàng" để chọn kho mặc định', 'supership-woocommerce' ),
			);
		}
		
		return array( 'valid' => true );
	}

	/**
	 * Resolve addresses to SuperShip canonical names
	 * 
	 * @param array $params Input parameters
	 * @return array {success: bool, pickup_*, receiver_*, error?}
	 */
	private function resolve_addresses( array $params ): array {
		$resolved = array( 'success' => true );
		
		// Resolve pickup addresses (if manual mode)
		if ( empty( $params['pickup_code'] ) ) {
			$pickup = $this->resolve_address_set(
				$params['pickup_province'],
				$params['pickup_district'],
				$params['pickup_commune'],
				'pickup'
			);
			if ( ! $pickup['success'] ) {
				return $pickup;
			}
			$resolved = array_merge( $resolved, $pickup );
		}
		
		// Resolve receiver addresses (always required)
		$receiver = $this->resolve_address_set(
			$params['receiver_province'],
			$params['receiver_district'],
			$params['receiver_commune'],
			'receiver'
		);
		if ( ! $receiver['success'] ) {
			return $receiver;
		}
		
		return array_merge( $resolved, $receiver );
	}

	/**
	 * Resolve a set of province/district/commune
	 * 
	 * @param string $province Province name
	 * @param string $district District name
	 * @param string $commune Commune name
	 * @param string $prefix Prefix for result keys (pickup/receiver)
	 * @return array {success: bool, {prefix}_province?, {prefix}_district?, {prefix}_commune?, error?}
	 */
	private function resolve_address_set( string $province, string $district, string $commune, string $prefix ): array {
		// Resolve province
		$province_match = $this->address_repo->find_province_by_name( $province );
		if ( 'matched' !== $province_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( '%s province "%s" not found', 'supership-woocommerce' ),
					ucfirst( $prefix ),
					$province
				),
			);
		}
		
		// Resolve district
		$district_match = $this->address_repo->find_district_by_name( $province_match['code'], $district );
		if ( 'matched' !== $district_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( '%s district "%s" not found', 'supership-woocommerce' ),
					ucfirst( $prefix ),
					$district
				),
			);
		}
		
		// Resolve commune
		$commune_match = $this->address_repo->find_commune_by_name( $district_match['code'], $commune );
		if ( 'matched' !== $commune_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( '%s commune "%s" not found', 'supership-woocommerce' ),
					ucfirst( $prefix ),
					$commune
				),
			);
		}
		
		return array(
			'success'                => true,
			"{$prefix}_province"     => $province_match['name'],
			"{$prefix}_district"     => $district_match['name'],
			"{$prefix}_commune"      => $commune_match['name'],
		);
	}

	/**
	 * Build SuperShip API request body
	 * 
	 * @param array $params Input parameters
	 * @param array $resolved Resolved addresses
	 * @return array Request body
	 */
	private function build_request_body( array $params, array $resolved ): array {
		$body = array(
			// Receiver (required)
			'name'     => $params['receiver_name'],
			'phone'    => $params['receiver_phone'],
			'address'  => $params['receiver_address'],
			'province' => $resolved['receiver_province'],
			'district' => $resolved['receiver_district'],
			'commune'  => $resolved['receiver_commune'],
			
			// Package details
			'weight'  => (int) $params['weight_grams'],
			'value'   => (int) $params['declared_value'],
			'amount'  => (int) $params['cod_amount'],
			
			// Service config
			'service'      => (string) $params['service'],
			'config'       => (string) $params['config'],
			'payer'        => (string) $params['payer'],
			'product_type' => (string) $params['product_type'],
			
			// Idempotency key
			'soc' => (string) $params['soc'],
		);
		
		// Pickup: either warehouse code or manual address
		if ( ! empty( $params['pickup_code'] ) ) {
			$body['pickup_code'] = $params['pickup_code'];
		} else {
			$body['pickup_phone']    = $params['pickup_phone'];
			$body['pickup_address']  = $params['pickup_address'];
			$body['pickup_province'] = $resolved['pickup_province'];
			$body['pickup_district'] = $resolved['pickup_district'];
			$body['pickup_commune']  = $resolved['pickup_commune'];
			
			// Optional pickup fields
			if ( ! empty( $params['pickup_name'] ) ) {
				$body['pickup_name'] = $params['pickup_name'];
			}
			if ( ! empty( $params['pickup_contact'] ) ) {
				$body['pickup_contact'] = $params['pickup_contact'];
			}
		}
		
		// Product info
		if ( '1' === $params['product_type'] && ! empty( $params['product'] ) ) {
			$body['product'] = $params['product'];
		} elseif ( '2' === $params['product_type'] && ! empty( $params['products'] ) ) {
			$body['products'] = $params['products'];
		}
		
		// Optional fields
		if ( ! empty( $params['note'] ) ) {
			$body['note'] = $params['note'];
		}
		
		if ( ! empty( $params['barter'] ) ) {
			$body['barter'] = $params['barter'];
		}
		
		if ( ! empty( $params['partner'] ) ) {
			$body['partner'] = $params['partner'];
		}
		
		return $body;
	}

	/**
	 * Transform SuperShip shipment response
	 * 
	 * @param array $results SuperShip API results
	 * @return array Transformed shipment
	 */
	private function transform_shipment( array $results ): array {
		return array(
			'tracking_number' => isset( $results['code'] ) ? $results['code'] : '',
			'shortcode'       => isset( $results['shortcode'] ) ? $results['shortcode'] : '',
			'sorting_code'    => isset( $results['sorting'] ) ? $results['sorting'] : '',
			'soc'             => isset( $results['soc'] ) ? $results['soc'] : '',
			'status'          => isset( $results['status'] ) ? (int) $results['status'] : 0,
			'status_name'     => isset( $results['status_name'] ) ? $results['status_name'] : '',
			'fee'             => isset( $results['fee'] ) ? (int) $results['fee'] : 0,
			'insurance'       => isset( $results['insurance'] ) ? (int) $results['insurance'] : 0,
			'cod_amount'      => isset( $results['amount'] ) ? (int) $results['amount'] : 0,
			'collection'      => isset( $results['collection'] ) ? (int) $results['collection'] : 0,
			'declared_value'  => isset( $results['value'] ) ? (int) $results['value'] : 0,
			'weight'          => isset( $results['weight'] ) ? (int) $results['weight'] : 0,
			'receiver_phone'  => isset( $results['phone'] ) ? $results['phone'] : '',
		);
	}

	/**
	 * Check if error is duplicate shipment
	 * 
	 * @param SuperShip_API_Response $response API response
	 * @return bool
	 */
	private function is_duplicate_error( SuperShip_API_Response $response ): bool {
		$message = strtolower( $response->get_message() );
		
		$duplicate_keywords = array(
			'duplicate',
			'đã tồn tại',
			'trùng',
			'soc',
		);
		
		foreach ( $duplicate_keywords as $keyword ) {
			if ( false !== strpos( $message, $keyword ) ) {
				return true;
			}
		}
		
		return false;
	}
}
