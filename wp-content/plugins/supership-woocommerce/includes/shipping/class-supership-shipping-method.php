<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Shipping Method
 * 
 * WooCommerce shipping method integration.
 * 
 * Features:
 * - Real-time rate calculation via SuperShip API
 * - Multiple service options (Standard/Express)
 * - Address validation
 * - Automatic shipment creation on order
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
class SuperShip_Shipping_Method extends WC_Shipping_Method {
	
	/** @var SuperShip_Rate_Service */
	private $rate_service;
	
	/** @var SuperShip_Address_Repository */
	private $address_repo;

	/**
	 * Constructor
	 * 
	 * @param int $instance_id Shipping instance ID
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'supership';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'SuperShip', 'supership-woocommerce' );
		$this->method_description = __( 'Phí vận chuyển tính trực tiếp từ SuperShip theo địa chỉ nhận hàng', 'supership-woocommerce' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);
		
		$this->init();
		
		$this->rate_service = new SuperShip_Rate_Service();
		$this->address_repo = new SuperShip_Address_Repository();
	}

	/**
	 * Initialize settings
	 */
	private function init(): void {
		$this->init_form_fields();
		$this->init_settings();
		
		$this->title   = $this->get_option( 'title', $this->method_title );
		$this->enabled = $this->get_option( 'enabled', 'yes' );
		
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize form fields
	 */
	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title'              => array(
				'title'       => __( 'Tên hiển thị', 'supership-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Tên phương thức giao hàng mà khách nhìn thấy ở trang thanh toán.', 'supership-woocommerce' ),
				'default'     => __( 'SuperShip', 'supership-woocommerce' ),
				'desc_tip'    => true,
			),
			'enabled'            => array(
				'title'   => __( 'Bật/Tắt', 'supership-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Bật giao hàng qua SuperShip', 'supership-woocommerce' ),
				'default' => 'yes',
			),
			'show_services'      => array(
				'title'       => __( 'Gói dịch vụ', 'supership-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Cho khách chọn giữa nhiều gói dịch vụ', 'supership-woocommerce' ),
				'description' => __( 'Hiện riêng từng gói (Tiêu chuẩn, Tốc hành...) để khách tự chọn. Tắt thì chỉ hiện gói rẻ nhất.', 'supership-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'fallback_enabled'   => array(
				'title'       => __( 'Phí dự phòng', 'supership-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Dùng phí cố định khi không tính được phí từ SuperShip', 'supership-woocommerce' ),
				'description' => __( 'Khi SuperShip không phản hồi (mất mạng, bảo trì...), khách vẫn đặt hàng được với mức phí cố định bên dưới thay vì bị kẹt ở trang thanh toán.', 'supership-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'fallback_amount'    => array(
				'title'       => __( 'Mức phí dự phòng', 'supership-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'Số tiền phí ship cố định dùng khi không tính được phí thật.', 'supership-woocommerce' ),
				'default'     => '30000',
				'desc_tip'    => true,
			),
			'shop_pays_shipping' => array(
				'title'       => __( 'Shop chịu phí ship', 'supership-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Hiển thị miễn phí vận chuyển cho khách, shop tự chịu phí ship', 'supership-woocommerce' ),
				'description' => __( 'Khách thấy "Miễn phí giao hàng" ở trang thanh toán và không phải trả phí ship. Phí ship thật vẫn được tính từ SuperShip và chỉ hiển thị cho admin trong trang quản lý đơn hàng.', 'supership-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'free_shipping_min'  => array(
				'title'       => __( 'Miễn phí ship từ', 'supership-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'Đơn hàng từ số tiền này trở lên được miễn phí giao hàng (để 0 nếu không dùng).', 'supership-woocommerce' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Calculate shipping rates
	 * 
	 * @param array $package Shipping package
	 */
	public function calculate_shipping( $package = array() ): void {
		// Validate package
		if ( empty( $package['destination'] ) ) {
			return;
		}
		
		$destination = $package['destination'];
		
		// Check required address fields
		if ( empty( $destination['state'] ) || empty( $destination['city'] ) ) {
			$this->add_fallback_rate();
			return;
		}
		
		// Get sender address from default warehouse
		$sender = $this->get_sender_address();
		if ( ! $sender ) {
			$this->add_fallback_rate();
			return;
		}
		
		// Calculate package weight (in grams)
		$weight_grams = $this->calculate_package_weight( $package );
		
		// Calculate declared value
		$declared_value = $this->calculate_package_value( $package );
		
		// Check free shipping
		if ( $this->is_free_shipping_eligible( $package ) ) {
			$this->add_rate( array(
				'id'    => $this->id . ':free',
				'label' => __( 'Miễn phí giao hàng', 'supership-woocommerce' ),
				'cost'  => 0,
			) );
			return;
		}
		
		// Call SuperShip Rate API
		$rate_result = $this->rate_service->calculate( array(
			'sender_province'   => $sender['province'],
			'sender_district'   => $sender['district'],
			'receiver_province' => $destination['state'],
			'receiver_district' => $destination['city'],
			'weight_grams'      => $weight_grams,
			'declared_value'    => $declared_value,
		) );
		
		if ( ! $rate_result['success'] ) {
			$this->add_fallback_rate();
			return;
		}
		
		// Add rates
		$this->add_supership_rates( $rate_result['rates'] );
	}

	/**
	 * Add SuperShip rates to checkout
	 * 
	 * @param array $rates Rates from SuperShip
	 */
	private function add_supership_rates( array $rates ): void {
		if ( empty( $rates ) ) {
			$this->add_fallback_rate();
			return;
		}
		
		if ( $this->shop_pays_shipping() ) {
			// Shop absorbs the fee: customer sees free shipping, real fee kept in hidden meta for admin.
			$cheapest = $this->get_cheapest_rate( $rates );

			$this->add_rate( array(
				'id'    => $this->id . ':shop-pays',
				'label' => __( 'Miễn phí giao hàng', 'supership-woocommerce' ),
				'cost'  => 0,
				'meta_data' => array(
					'_supership_actual_fee'   => $cheapest['total'],
					'_supership_service_code' => $cheapest['service_code'],
					'_supership_service_name' => $cheapest['service_name'],
				),
			) );
			return;
		}

		$show_services = 'yes' === $this->get_option( 'show_services', 'yes' );

		if ( $show_services ) {
			// Show each service as separate option
			foreach ( $rates as $rate ) {
				$this->add_rate( array(
					'id'       => $this->id . ':' . $rate['service_code'],
					'label'    => $rate['service_name'],
					'cost'     => $rate['total'],
					'meta_data' => array(
						'service_code' => $rate['service_code'],
						'service_name' => $rate['service_name'],
						'fee'          => $rate['fee'],
						'insurance'    => $rate['insurance'],
					),
				) );
			}
		} else {
			// Show cheapest rate only
			$cheapest = $this->get_cheapest_rate( $rates );
			
			$this->add_rate( array(
				'id'    => $this->id,
				'label' => $this->title,
				'cost'  => $cheapest['total'],
				'meta_data' => array(
					'service_code' => $cheapest['service_code'],
					'service_name' => $cheapest['service_name'],
					'fee'          => $cheapest['fee'],
					'insurance'    => $cheapest['insurance'],
				),
			) );
		}
	}

	/**
	 * Add fallback rate
	 */
	private function add_fallback_rate(): void {
		if ( 'yes' !== $this->get_option( 'fallback_enabled', 'yes' ) ) {
			return;
		}
		
		$amount = (float) $this->get_option( 'fallback_amount', '30000' );

		if ( $this->shop_pays_shipping() ) {
			$this->add_rate( array(
				'id'    => $this->id . ':fallback',
				'label' => __( 'Miễn phí giao hàng', 'supership-woocommerce' ),
				'cost'  => 0,
				'meta_data' => array(
					'_supership_actual_fee' => $amount,
				),
			) );
			return;
		}

		$this->add_rate( array(
			'id'    => $this->id . ':fallback',
			'label' => $this->title,
			'cost'  => $amount,
		) );
	}

	/**
	 * Whether the shop absorbs the shipping fee (customer sees free shipping)
	 */
	private function shop_pays_shipping(): bool {
		return 'yes' === $this->get_option( 'shop_pays_shipping', 'no' );
	}

	/**
	 * Get sender address from default warehouse
	 * 
	 * @return array|null {province, district} or null
	 */
	private function get_sender_address(): ?array {
		$default_warehouse = get_option( 'supership_default_warehouse', '' );
		
		if ( '' === $default_warehouse ) {
			// Fallback: use store address
			return array(
				'province' => WC()->countries->get_base_state(),
				'district' => WC()->countries->get_base_city(),
			);
		}
		
		$warehouse_service = new SuperShip_Warehouse_Service();
		$result = $warehouse_service->get_warehouse( $default_warehouse );
		
		if ( ! $result['success'] ) {
			return null;
		}
		
		return array(
			'province' => $result['warehouse']['province'],
			'district' => $result['warehouse']['district'],
		);
	}

	/**
	 * Calculate package weight in grams
	 *
	 * Reads the store's actual WooCommerce weight unit (Settings > Products >
	 * Measurements) instead of assuming kg - a store configured for "g" would
	 * otherwise have its product weights multiplied by 1000 twice, producing
	 * absurd totals that SuperShip's API rejects (max 50000g/shipment).
	 *
	 * @param array $package Shipping package
	 * @return int Weight in grams
	 */
	private function calculate_package_weight( array $package ): int {
		static $grams_per_unit = array(
			'kg'  => 1000,
			'g'   => 1,
			'lbs' => 453.592,
			'oz'  => 28.3495,
		);

		$unit   = get_option( 'woocommerce_weight_unit', 'kg' );
		$factor = $grams_per_unit[ $unit ] ?? 1000;

		$weight = 0;

		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			$product_weight = (float) $product->get_weight();

			$weight += $product_weight * $item['quantity'] * $factor;
		}

		// Minimum weight: 100g
		return max( 100, (int) round( $weight ) );
	}

	/**
	 * Calculate package declared value
	 * 
	 * @param array $package Shipping package
	 * @return int Value in VND
	 */
	private function calculate_package_value( array $package ): int {
		$value = 0;
		
		foreach ( $package['contents'] as $item ) {
			$value += $item['line_total'] + $item['line_tax'];
		}
		
		return (int) $value;
	}

	/**
	 * Check if free shipping is eligible
	 * 
	 * @param array $package Shipping package
	 * @return bool
	 */
	private function is_free_shipping_eligible( array $package ): bool {
		$min_amount = (float) $this->get_option( 'free_shipping_min', '0' );
		
		if ( $min_amount <= 0 ) {
			return false;
		}
		
		$total = $this->calculate_package_value( $package );
		
		return $total >= $min_amount;
	}

	/**
	 * Get cheapest rate from rates array
	 * 
	 * @param array $rates Rates array
	 * @return array Cheapest rate
	 */
	private function get_cheapest_rate( array $rates ): array {
		$cheapest = $rates[0];
		
		foreach ( $rates as $rate ) {
			if ( $rate['total'] < $cheapest['total'] ) {
				$cheapest = $rate;
			}
		}
		
		return $cheapest;
	}
}
