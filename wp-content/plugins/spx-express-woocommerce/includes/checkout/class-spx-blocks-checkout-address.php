<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Store API schema, validation, and order persistence for Checkout Blocks.
 *
 * Mode-guarded: only activates when SPX_Checkout_Mode_Detector::is_blocks().
 * Never uses DOM manipulation. Only registers extension data and fields.
 */
final class SPX_Blocks_Checkout_Address {
	const NAMESPACE = 'spx-express';

	public static function init(): void {
		// Block type registration is always safe — it just registers metadata.
		add_action( 'init', array( __CLASS__, 'register_block_type' ) );
		// Store API extensions only for Blocks mode.
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'extend_store_api' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'update_order_from_request' ), 20, 2 );
	}

	public static function register_block_type(): void {
		if ( ! function_exists( 'register_block_type' ) ) { return; }
		register_block_type( 'spx-express/shipping-address', array(
			'api_version' => 3,
			'parent'      => array( 'woocommerce/checkout-shipping-address-block' ),
			'attributes'  => array(
				'lock' => array(
					'type'    => 'object',
					'default' => array( 'remove' => true, 'move' => true ),
				),
			),
			'supports'        => array( 'html' => false, 'align' => false, 'multiple' => false, 'reusable' => false ),
			'render_callback' => static function ( array $attributes, string $content ): string { return $content; },
		) );
	}

	public static function register_integration( $registry ): void {
		$registry->register( new SPX_Blocks_Checkout_Integration() );
	}

	public static function extend_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) { return; }
		woocommerce_store_api_register_endpoint_data( array(
			'endpoint'        => CheckoutSchema::IDENTIFIER,
			'namespace'       => self::NAMESPACE,
			'schema_callback' => array( __CLASS__, 'extension_schema' ),
		) );
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback( array(
				'namespace' => self::NAMESPACE,
				'callback'  => array( __CLASS__, 'update_cart_selection' ),
			) );
		}
	}

	public static function update_cart_selection( $data ): void {
		$data  = is_array( $data ) ? $data : array();
		$clean = array();
		foreach ( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) as $key ) {
			$clean[ $key ] = isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		}
		$is_cod = function_exists( 'WC' ) && WC() && WC()->session
			&& 'cod' === (string) WC()->session->get( 'chosen_payment_method', '' );
		$result = SPX_Classic_Checkout_Address::validate_payload( $clean, $is_cod, new SPX_Checkout_Address_Service() );
		if ( empty( $result['valid'] ) ) {
			throw new RouteException(
				'spx_checkout_address_' . $result['error'],
				SPX_Classic_Checkout_Address::message_for_error( $result['error'] ),
				400
			);
		}
		SPX_Classic_Checkout_Address::remember_snapshot( $result['snapshot'] );
	}

	public static function extension_schema(): array {
		$schema = array();
		foreach ( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) as $field ) {
			$schema[ $field ] = array(
				'description'  => 'Canonical local SPX checkout address identifier.',
				'type'         => 'string',
				'context'      => array(),
				'arg_options'  => array(
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return is_string( $value ) && strlen( $value ) <= 32;
					},
				),
			);
		}
		return $schema;
	}

	public static function update_order_from_request( WC_Order $order, WP_REST_Request $request ): void {
		if ( ! self::order_requires_selection( $order ) ) { return; }
		$extensions = (array) $request->get_param( 'extensions' );
		$payload    = isset( $extensions[ self::NAMESPACE ] ) && is_array( $extensions[ self::NAMESPACE ] )
			? $extensions[ self::NAMESPACE ]
			: array();
		$clean = array();
		foreach ( array( 'province_id', 'district_id', 'ward_id', 'dataset_version' ) as $key ) {
			$clean[ $key ] = isset( $payload[ $key ] ) ? sanitize_text_field( (string) $payload[ $key ] ) : '';
		}
		$result = SPX_Classic_Checkout_Address::validate_payload(
			$clean,
			'cod' === $order->get_payment_method(),
			new SPX_Checkout_Address_Service()
		);
		if ( empty( $result['valid'] ) ) {
			throw new RouteException(
				'spx_checkout_address_' . $result['error'],
				SPX_Classic_Checkout_Address::message_for_error( $result['error'] ),
				400
			);
		}
		SPX_Checkout_Address_Snapshot::write_to_order(
			$order,
			$result['snapshot'],
			SPX_Checkout_Address_Snapshot::SOURCE_BLOCKS_CHECKOUT
		);
		SPX_Classic_Checkout_Address::remember_snapshot( $result['snapshot'] );
	}

	public static function order_requires_selection( WC_Order $order ): bool {
		$needs_shipping = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( $product && $product->needs_shipping() ) {
				$needs_shipping = true;
				break;
			}
		}
		$methods = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$methods[] = $item->get_method_id();
		}
		return SPX_Checkout_Eligibility::requires_selection( $needs_shipping, $methods );
	}
}
