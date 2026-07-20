<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Label Service
 * 
 * Generates shipping labels (2-step process).
 * 
 * Step 1: POST /v1/partner/orders/token → get token
 * Step 2: GET https://khachhang.supership.vn/orders/awb?token={token}&size={size} → print URL
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Label_Service {
	
	const PRINT_BASE_URL = 'https://khachhang.supership.vn/orders/awb';
	
	/** @var SuperShip_Http_Client_Interface */
	private $client;

	/**
	 * @param SuperShip_Http_Client_Interface|null $client HTTP client
	 */
	public function __construct( SuperShip_Http_Client_Interface $client = null ) {
		$this->client = $client ?: new SuperShip_HTTP_Client();
	}

	/**
	 * Get label print URL
	 * 
	 * @param array  $tracking_numbers Array of SuperShip tracking numbers
	 * @param string $size Paper size (A5, K46, T2, K50, K75, K80, S8-S14)
	 * @return array {success: bool, print_url?: string, token?: string, error?: string}
	 */
	public function get_print_url( array $tracking_numbers, string $size = 'A5' ): array {
		// Validate inputs
		if ( empty( $tracking_numbers ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Cần ít nhất một mã vận đơn', 'supership-woocommerce' ),
			);
		}
		
		if ( ! $this->is_valid_size( $size ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					__( 'Khổ giấy không hợp lệ: %s', 'supership-woocommerce' ),
					$size
				),
			);
		}
		
		// Step 1: Get token from SuperShip
		$token_result = $this->get_token( $tracking_numbers );
		
		if ( ! $token_result['success'] ) {
			return $token_result;
		}
		
		// Step 2: Build print URL
		$print_url = $this->build_print_url( $token_result['token'], $size );
		
		return array(
			'success'   => true,
			'print_url' => $print_url,
			'token'     => $token_result['token'],
		);
	}

	/**
	 * Get token from SuperShip API
	 * 
	 * @param array $tracking_numbers Tracking numbers
	 * @return array {success: bool, token?: string, error?: string}
	 */
	private function get_token( array $tracking_numbers ): array {
		// Call SuperShip API
		$response = $this->client->post(
			'/v1/partner/orders/token',
			array( 'code' => array_values( $tracking_numbers ) )
		);
		
		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}
		
		// Parse response
		$results = $response->get_results();
		
		if ( ! is_array( $results ) || ! isset( $results['token'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Không lấy được mã in phiếu từ SuperShip', 'supership-woocommerce' ),
			);
		}
		
		$token = (string) $results['token'];
		
		if ( '' === $token ) {
			return array(
				'success' => false,
				'error'   => __( 'Không lấy được mã in phiếu từ SuperShip', 'supership-woocommerce' ),
			);
		}
		
		return array(
			'success' => true,
			'token'   => $token,
		);
	}

	/**
	 * Build print URL
	 * 
	 * @param string $token Token from SuperShip
	 * @param string $size Paper size
	 * @return string Print URL
	 */
	private function build_print_url( string $token, string $size ): string {
		return add_query_arg(
			array(
				'token' => $token,
				'size'  => $size,
			),
			self::PRINT_BASE_URL
		);
	}

	/**
	 * Validate paper size
	 * 
	 * @param string $size Paper size
	 * @return bool
	 */
	private function is_valid_size( string $size ): bool {
		$valid_sizes = array(
			'A5',
			'K46',
			'T2',
			'K50',
			'K75',
			'K80',
			'S8',
			'S9',
			'S10',
			'S11',
			'S12',
			'S13',
			'S14',
		);
		
		return in_array( strtoupper( $size ), $valid_sizes, true );
	}

	/**
	 * Get available paper sizes
	 * 
	 * @return array {code => label}
	 */
	public function get_available_sizes(): array {
		return array(
			'A5'  => __( 'A5 (148×210mm)', 'supership-woocommerce' ),
			'K46' => __( 'K46 (100×150mm)', 'supership-woocommerce' ),
			'T2'  => __( 'T2 (100×100mm)', 'supership-woocommerce' ),
			'K50' => __( 'K50 (75×120mm)', 'supership-woocommerce' ),
			'K75' => __( 'K75 (75×105mm)', 'supership-woocommerce' ),
			'K80' => __( 'K80 (80×80mm)', 'supership-woocommerce' ),
			'S8'  => __( 'S8 (80×120mm)', 'supership-woocommerce' ),
			'S9'  => __( 'S9 (80×100mm)', 'supership-woocommerce' ),
			'S10' => __( 'S10 (80×88mm)', 'supership-woocommerce' ),
			'S11' => __( 'S11 (80×60mm)', 'supership-woocommerce' ),
			'S12' => __( 'S12 (100×70mm)', 'supership-woocommerce' ),
			'S13' => __( 'S13 (100×80mm)', 'supership-woocommerce' ),
			'S14' => __( 'S14 (110×80mm)', 'supership-woocommerce' ),
		);
	}

	/**
	 * Get default paper size
	 * 
	 * @return string
	 */
	public function get_default_size(): string {
		return 'A5';
	}

	/**
	 * Print labels directly (opens in browser)
	 * 
	 * This method is for admin/CLI use - it redirects to the print URL.
	 * For programmatic use, call get_print_url() instead.
	 * 
	 * @param array  $tracking_numbers Tracking numbers
	 * @param string $size Paper size
	 * @return void (redirects or exits with error)
	 */
	public function print_labels( array $tracking_numbers, string $size = 'A5' ): void {
		$result = $this->get_print_url( $tracking_numbers, $size );
		
		if ( ! $result['success'] ) {
			wp_die(
				esc_html( $result['error'] ),
				esc_html__( 'Lỗi in phiếu gửi', 'supership-woocommerce' ),
				array( 'response' => 400 )
			);
		}
		
		// Redirect to print URL
		wp_redirect( $result['print_url'] );
		exit;
	}
}
