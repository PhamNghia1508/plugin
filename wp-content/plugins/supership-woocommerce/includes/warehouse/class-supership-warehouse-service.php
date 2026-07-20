<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Warehouse Service
 *
 * Manages SuperShip warehouses (pickup locations).
 *
 * Endpoints (docs/integrations/supership-api-reference.md section 5):
 * - GET  /v1/partner/warehouses         - Get all warehouses
 * - POST /v1/partner/warehouses/create  - Create warehouse
 * - POST /v1/partner/warehouses/update  - Update warehouse (name/phone/contact only)
 * - There is no delete endpoint documented by SuperShip.
 *
 * Warehouse fields (per docs, identifier is `code`, not `pickup_code`):
 * - code (from API after creation)
 * - name, phone, contact, address
 * - province, district, commune (exact canonical names)
 * - primary: '1' = default warehouse, '2' = normal (required on create)
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Warehouse_Service {

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
	 * Get all warehouses
	 *
	 * @return array {success: bool, warehouses?: array, error?: string}
	 */
	public function get_warehouses(): array {
		$response = $this->client->get( '/v1/partner/warehouses' );

		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}

		$results = $response->get_results();

		if ( ! is_array( $results ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip trả về danh sách kho không đọc được', 'supership-woocommerce' ),
			);
		}

		// Transform to standard format
		$warehouses = $this->transform_warehouses( $results );

		return array(
			'success'    => true,
			'warehouses' => $warehouses,
		);
	}

	/**
	 * Get single warehouse by code
	 *
	 * @param string $code Warehouse code
	 * @return array {success: bool, warehouse?: array, error?: string}
	 */
	public function get_warehouse( string $code ): array {
		$result = $this->get_warehouses();

		if ( ! $result['success'] ) {
			return $result;
		}

		foreach ( $result['warehouses'] as $warehouse ) {
			if ( $warehouse['code'] === $code ) {
				return array(
					'success'   => true,
					'warehouse' => $warehouse,
				);
			}
		}

		return array(
			'success' => false,
			'error'   => __( 'Không tìm thấy kho', 'supership-woocommerce' ),
		);
	}

	/**
	 * Create warehouse
	 *
	 * @param array $params Warehouse parameters. Optional 'primary': '1' = default
	 *                       warehouse, '2' = normal (defaults to '2').
	 * @return array {success: bool, warehouse?: array, error?: string}
	 */
	public function create_warehouse( array $params ): array {
		// Validate params
		$validation = $this->validate_warehouse_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}

		// Resolve addresses
		$resolved = $this->resolve_warehouse_address( $params );
		if ( ! $resolved['success'] ) {
			return $resolved;
		}

		// Build request body
		$body = array(
			'name'     => $params['name'],
			'phone'    => $params['phone'],
			'contact'  => ! empty( $params['contact'] ) ? $params['contact'] : $params['name'],
			'address'  => $params['address'],
			'province' => $resolved['province'],
			'district' => $resolved['district'],
			'commune'  => $resolved['commune'],
			'primary'  => ! empty( $params['primary'] ) ? (string) $params['primary'] : '2',
		);

		// Optional: large e-commerce partner code.
		$partner = ! empty( $params['partner'] ) ? $params['partner'] : ( class_exists( 'SuperShip_Auth_Config' ) ? SuperShip_Auth_Config::get_stored_partner_code() : '' );
		if ( '' !== $partner ) {
			$body['partner'] = $partner;
		}

		// Call API
		$response = $this->client->post( '/v1/partner/warehouses/create', $body );

		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}

		$results = $response->get_results();

		if ( ! is_array( $results ) || ! isset( $results['code'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip trả về dữ liệu không đọc được khi tạo kho', 'supership-woocommerce' ),
			);
		}

		return array(
			'success'   => true,
			'warehouse' => $this->transform_warehouse( $results ),
		);
	}

	/**
	 * Update warehouse
	 *
	 * Per SuperShip docs (section 5.3), this endpoint can only change
	 * name/phone/contact. To change address/province/district/commune, a
	 * new warehouse must be created instead.
	 *
	 * @param string $code Warehouse code
	 * @param array  $params Update parameters (name, phone, contact - at least one required)
	 * @return array {success: bool, warehouse?: array, error?: string}
	 */
	public function update_warehouse( string $code, array $params ): array {
		if ( '' === $code ) {
			return array(
				'success' => false,
				'error'   => __( 'Thiếu mã kho', 'supership-woocommerce' ),
			);
		}

		$body = array( 'code' => $code );

		// Only name/phone/contact are updatable per SuperShip docs.
		$updatable_fields = array( 'name', 'phone', 'contact' );
		foreach ( $updatable_fields as $field ) {
			if ( isset( $params[ $field ] ) && '' !== $params[ $field ] ) {
				$body[ $field ] = $params[ $field ];
			}
		}

		if ( 1 === count( $body ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Cần nhập ít nhất tên, số điện thoại hoặc người liên hệ', 'supership-woocommerce' ),
			);
		}

		// Call API
		$response = $this->client->post( '/v1/partner/warehouses/update', $body );

		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}

		$results = $response->get_results();

		return array(
			'success'   => true,
			'warehouse' => is_array( $results ) ? $results : array(),
		);
	}

	/**
	 * Delete warehouse
	 *
	 * SuperShip does not document a delete endpoint for warehouses (see
	 * docs/integrations/supership-api-reference.md section 5). Deactivate
	 * unwanted warehouses from the SuperShip dashboard instead.
	 *
	 * @param string $code Warehouse code (unused - kept for interface stability)
	 * @return array {success: bool, error: string}
	 */
	public function delete_warehouse( string $code ): array {
		return array(
			'success' => false,
			'error'   => __( 'SuperShip không hỗ trợ xoá kho qua API. Vui lòng tắt kho trên trang quản trị SuperShip (khachhang.supership.vn).', 'supership-woocommerce' ),
		);
	}

	/**
	 * Validate warehouse parameters
	 *
	 * @param array $params Parameters to validate
	 * @return array {valid: bool, error?: string}
	 */
	private function validate_warehouse_params( array $params ): array {
		$required = array(
			'name'     => __( 'Thiếu tên kho', 'supership-woocommerce' ),
			'phone'    => __( 'Thiếu số điện thoại', 'supership-woocommerce' ),
			'address'  => __( 'Thiếu địa chỉ', 'supership-woocommerce' ),
			'province' => __( 'Thiếu Tỉnh/Thành', 'supership-woocommerce' ),
			'district' => __( 'Thiếu Quận/Huyện', 'supership-woocommerce' ),
			'commune'  => __( 'Thiếu Phường/Xã', 'supership-woocommerce' ),
		);

		foreach ( $required as $field => $message ) {
			if ( ! isset( $params[ $field ] ) || '' === $params[ $field ] ) {
				return array(
					'valid' => false,
					'error' => $message,
				);
			}
		}

		return array( 'valid' => true );
	}

	/**
	 * Resolve warehouse address to canonical names
	 *
	 * @param array $params Parameters with province/district/commune
	 * @return array {success: bool, province?, district?, commune?, error?}
	 */
	private function resolve_warehouse_address( array $params ): array {
		// Resolve province
		$province_match = $this->address_repo->find_province_by_name( $params['province'] );
		if ( 'matched' !== $province_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Tỉnh/Thành "%s"', 'supership-woocommerce' ),
					$params['province']
				),
			);
		}

		// Resolve district
		$district_match = $this->address_repo->find_district_by_name(
			$province_match['code'],
			$params['district']
		);
		if ( 'matched' !== $district_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Quận/Huyện "%s"', 'supership-woocommerce' ),
					$params['district']
				),
			);
		}

		// Resolve commune
		$commune_match = $this->address_repo->find_commune_by_name(
			$district_match['code'],
			$params['commune']
		);
		if ( 'matched' !== $commune_match['status'] ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Không tìm thấy Phường/Xã "%s"', 'supership-woocommerce' ),
					$params['commune']
				),
			);
		}

		return array(
			'success'  => true,
			'province' => $province_match['name'],
			'district' => $district_match['name'],
			'commune'  => $commune_match['name'],
		);
	}

	/**
	 * Transform warehouses array
	 *
	 * @param array $results SuperShip API results
	 * @return array Transformed warehouses
	 */
	private function transform_warehouses( array $results ): array {
		$warehouses = array();

		foreach ( $results as $item ) {
			if ( is_array( $item ) ) {
				$warehouses[] = $this->transform_warehouse( $item );
			}
		}

		return $warehouses;
	}

	/**
	 * Transform single warehouse
	 *
	 * @param array $item SuperShip warehouse data
	 * @return array Transformed warehouse
	 */
	private function transform_warehouse( array $item ): array {
		return array(
			'code'         => isset( $item['code'] ) ? $item['code'] : '',
			'name'         => isset( $item['name'] ) ? $item['name'] : '',
			'phone'        => isset( $item['phone'] ) ? $item['phone'] : '',
			'contact'      => isset( $item['contact'] ) ? $item['contact'] : '',
			'address'      => isset( $item['address'] ) ? $item['address'] : '',
			'formatted_address' => isset( $item['formatted_address'] ) ? $item['formatted_address'] : '',
			// The warehouse list/detail response names these *_name (e.g. "province_name") -
			// distinct from the plain "province"/"district"/"commune" keys the create/update
			// request body uses. Prefer the *_name form, fall back to the short form so this
			// keeps working if SuperShip ever unifies the naming.
			'province'     => isset( $item['province_name'] ) ? $item['province_name'] : ( isset( $item['province'] ) ? $item['province'] : '' ),
			'district'     => isset( $item['district_name'] ) ? $item['district_name'] : ( isset( $item['district'] ) ? $item['district'] : '' ),
			'commune'      => isset( $item['commune_name'] ) ? $item['commune_name'] : ( isset( $item['commune'] ) ? $item['commune'] : '' ),
			'status'       => isset( $item['status'] ) ? (int) $item['status'] : 0,
			'status_name'  => isset( $item['status_name'] ) ? $item['status_name'] : '',
			'primary'      => isset( $item['primary'] ) ? (int) $item['primary'] : 0,
			'primary_name' => isset( $item['primary_name'] ) ? $item['primary_name'] : '',
		);
	}

	/**
	 * Get default warehouse (first warehouse or null)
	 *
	 * @return array|null Warehouse or null
	 */
	public function get_default_warehouse(): ?array {
		$result = $this->get_warehouses();

		if ( ! $result['success'] || empty( $result['warehouses'] ) ) {
			return null;
		}

		return $result['warehouses'][0];
	}

	/**
	 * Check if warehouses are configured
	 *
	 * @return bool
	 */
	public function has_warehouses(): bool {
		$result = $this->get_warehouses();

		return $result['success'] && ! empty( $result['warehouses'] );
	}
}
