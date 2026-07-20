<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Shipping_Method extends WC_Shipping_Method {
	/** @var object|null */ private $dynamic_rate_service;
	public function __construct( $instance_id = 0, $dynamic_rate_service = null ) {
		$this->id                 = 'spx_express';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'SPX Express', 'spx-express-woocommerce' );
		$this->method_description = __( 'Fixed-rate SPX Express shipping with manual/mock shipment creation.', 'spx-express-woocommerce' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->dynamic_rate_service = $dynamic_rate_service;
		$this->init();
	}

	public static function init_audit_persistence(): void {
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'persist_shipping_item_audit' ), 20, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'persist_order_audit' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'persist_order_audit' ), 40 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'add_package_cache_signature' ), 20 );
	}

	public static function cache_signature( bool $enabled, int $multiplier, string $wp_environment ): string {
		return hash( 'sha256', ( $enabled ? 'on' : 'off' ) . '|' . $multiplier . '|' . strtolower( trim( $wp_environment ) ) . '|' . SPX_Fee_Conversion_Contract::VERSION );
	}

	/** PII-free signature of every matched-instance setting that can change a rate. */
	public static function settings_cache_signature( int $instance_id, array $settings ): string {
		$canonical = array( 'instance_id' => $instance_id );
		foreach ( array( 'enabled', 'base_cost', 'free_shipping_min_amount', 'environment', 'default_item_weight_grams' ) as $key ) {
			$canonical[ $key ] = isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		}
		$canonical['contract_version'] = SPX_Fee_Conversion_Contract::VERSION;
		return hash( 'sha256', function_exists( 'wp_json_encode' ) ? wp_json_encode( $canonical ) : json_encode( $canonical ) );
	}

	public static function add_package_cache_signature( array $packages ): array {
		$wp_environment=function_exists('wp_get_environment_type')?wp_get_environment_type():(defined('WP_ENVIRONMENT_TYPE')?WP_ENVIRONMENT_TYPE:'production');
		$enabled=defined('SPX_EXPERIMENTAL_DYNAMIC_RATE')&&true===SPX_EXPERIMENTAL_DYNAMIC_RATE&&'production'!==$wp_environment;
		$multiplier=defined('SPX_EXPERIMENTAL_FEE_MULTIPLIER')?(int)SPX_EXPERIMENTAL_FEE_MULTIPLIER:0;
		$signature=self::cache_signature($enabled,$multiplier,$wp_environment);
		foreach($packages as &$package){
			$package['spx_dynamic_rate_signature']=$signature;
			$package['spx_shipping_instance_signature']=self::matched_instance_signature($package);
		}unset($package);
		return $packages;
	}

	private static function matched_instance_signature( array $package ): string {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! method_exists( 'WC_Shipping_Zones', 'get_zone_matching_package' ) ) {
			return hash( 'sha256', 'unmatched' );
		}
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) {
			return hash( 'sha256', 'unmatched' );
		}
		$signatures = array();
		foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
			if ( ! is_object( $method ) || 'spx_express' !== (string) ( $method->id ?? '' ) || ! method_exists( $method, 'get_option' ) ) { continue; }
			$settings = array(
				'enabled' => (string) ( $method->enabled ?? '' ),
				'base_cost' => (string) $method->get_option( 'base_cost', '' ),
				'free_shipping_min_amount' => (string) $method->get_option( 'free_shipping_min_amount', '' ),
				'environment' => (string) $method->get_option( 'environment', '' ),
				'default_item_weight_grams' => (string) $method->get_option( 'default_item_weight_grams', '' ),
			);
			$signatures[] = self::settings_cache_signature( (int) ( $method->instance_id ?? 0 ), $settings );
		}
		sort( $signatures, SORT_STRING );
		return hash( 'sha256', $signatures ? implode( '|', $signatures ) : 'unmatched' );
	}

	private function init() {
		$this->init_instance_form_fields();
		$this->init_settings();
		$this->enabled = $this->get_option( 'enabled', 'yes' );
		$this->title   = $this->get_option( 'title', __( 'SPX Express', 'spx-express-woocommerce' ) );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_instance_form_fields() {
		$this->instance_form_fields = array(
			'enabled' => array( 'title' => __( 'Enable', 'spx-express-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Enable this shipping method', 'spx-express-woocommerce' ), 'default' => 'yes' ),
			'title' => array( 'title' => __( 'Checkout title', 'spx-express-woocommerce' ), 'type' => 'text', 'default' => __( 'SPX Express', 'spx-express-woocommerce' ), 'sanitize_callback' => 'sanitize_text_field' ),
			'base_cost' => array( 'title' => __( 'Base cost', 'spx-express-woocommerce' ), 'type' => 'price', 'default' => '0' ),
			'free_shipping_min_amount' => array( 'title' => __( 'Free shipping minimum', 'spx-express-woocommerce' ), 'type' => 'price', 'description' => __( 'Leave empty or use 0 to disable.', 'spx-express-woocommerce' ), 'default' => '' ),
			'estimated_delivery_days' => array( 'title' => __( 'Estimated delivery', 'spx-express-woocommerce' ), 'type' => 'text', 'placeholder' => __( '2–4 days', 'spx-express-woocommerce' ), 'sanitize_callback' => 'sanitize_text_field' ),
			'default_item_weight_grams' => array( 'title' => __( 'Default item weight (grams)', 'spx-express-woocommerce' ), 'type' => 'number', 'default' => '500', 'custom_attributes' => array( 'min' => '1', 'step' => '1' ) ),
			'environment' => array( 'title' => __( 'Provider', 'spx-express-woocommerce' ), 'type' => 'select', 'default' => 'mock', 'options' => array( 'mock' => __( 'Mock / Manual', 'spx-express-woocommerce' ) ), 'description' => __( 'Production activation is managed centrally on the SPX Express page and is currently unavailable.', 'spx-express-woocommerce' ) ),
		);
	}

	public static function normalize_amount( $value ): float {
		$value = wc_format_decimal( $value );
		return max( 0.0, is_numeric( $value ) ? (float) $value : 0.0 );
	}

	public static function qualifies_for_free_shipping( float $subtotal, float $threshold ): bool {
		return $threshold > 0.0 && $subtotal >= $threshold;
	}

	public function validate_price_field( $key, $value ) {
		return wc_format_decimal( self::normalize_amount( $value ) );
	}

	public function validate_number_field( $key, $value ) {
		if ( 'default_item_weight_grams' === $key ) {
			return (string) max( 1, absint( $value ) );
		}
		return wc_format_decimal( self::normalize_amount( $value ) );
	}

	public function calculate_shipping( $package = array() ) {
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		$cost      = self::normalize_amount( $this->get_option( 'base_cost', '0' ) );
		$threshold = self::normalize_amount( $this->get_option( 'free_shipping_min_amount', '0' ) );
		$subtotal  = isset( $package['contents_cost'] ) ? max( 0.0, (float) $package['contents_cost'] ) : 0.0;
		if ( self::qualifies_for_free_shipping( $subtotal, $threshold ) ) { $cost = 0.0; }
		$label = 'SPX Express – Giao tiêu chuẩn';
		if ( self::qualifies_for_free_shipping( $subtotal, $threshold ) ) {
			$this->add_rate( array( 'id'=>$this->get_rate_id(), 'label'=>$label, 'cost'=>0.0, 'package'=>$package, 'meta_data'=>array('_spx_rate_source'=>'fixed_disabled_experiment') ) );
			return;
		}
		$service = $this->dynamic_rate_service ?: new SPX_Dynamic_Checkout_Rate_Service();
		$result = $service->calculate( $package, $cost, array( 'instance_environment'=>(string)$this->get_option('environment','mock') ) );
		// Only charge a positive, valid amount. An unconfigured/empty/invalid fee must
		// never be silently added as a 0-cost (FREE) rate — fail closed instead. The
		// intentional free-shipping-threshold rate is added earlier and returns above.
		$candidate = isset( $result['cost'] ) && is_numeric( $result['cost'] ) ? self::normalize_amount( $result['cost'] ) : 0.0;
		if ( empty( $result['add_rate'] ) || $candidate <= 0.0 ) {
			$message = __( 'Không thể tính phí giao hàng SPX lúc này. Vui lòng thử lại.', 'spx-express-woocommerce' );
			if ( function_exists( 'wc_add_notice' ) && ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $message, 'error' ) ) ) { wc_add_notice( $message, 'error' ); }
			return;
		}
		$this->add_rate( array( 'id'=>$this->get_rate_id(), 'label'=>$label, 'cost'=>$result['cost'], 'package'=>$package, 'meta_data'=>isset($result['audit'])&&is_array($result['audit'])?$result['audit']:array() ) );
	}

	public static function persist_shipping_item_audit( $item, $package_key, $package, $order ): void {
		if ( ! is_object($item) || ! is_object($order) ) { return; }
		$audit = array();
		foreach ( (array)($package['rates']??array()) as $rate ) {
			if ( !is_object($rate) || !method_exists($rate,'get_method_id') || 'spx_express'!==$rate->get_method_id() || !method_exists($rate,'get_meta_data') ) { continue; }
			$meta=$rate->get_meta_data();
			foreach(SPX_Dynamic_Checkout_Rate_Service::approved_audit_keys() as $key){if(array_key_exists($key,$meta))$audit[$key]=$meta[$key];}
			break;
		}
		if(!empty($audit))SPX_Dynamic_Checkout_Rate_Service::persist_audit($item,$order,$audit);
	}

	public static function persist_order_audit( $order ): void {
		if ( !is_object($order) || !method_exists($order,'get_items') ) { return; }
		foreach($order->get_items('shipping') as $item){$audit=array();foreach(SPX_Dynamic_Checkout_Rate_Service::approved_audit_keys() as $key){$value=$item->get_meta($key,true);if(''!==(string)$value)$audit[$key]=$value;}if(!empty($audit))SPX_Dynamic_Checkout_Rate_Service::persist_audit($item,$order,$audit);}
	}
}
