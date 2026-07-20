<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Checkout Areas REST Controller
 *
 * Exposes SuperShip's Areas API (province -> district -> commune) to the
 * checkout page's JavaScript, so the customer picks real, canonical
 * SuperShip location names instead of typing free text that might fail the
 * fuzzy-match resolver at rate/shipment-creation time.
 *
 * This is a thin, read-only proxy over SuperShip_Address_Repository (which
 * already caches SuperShip's province/district/commune data for 24h) - it
 * does not call SuperShip directly and does not require authentication,
 * matching the public nature of GET /v1/partner/areas/* per the docs.
 *
 * @package SuperShip_WooCommerce
 * @version 0.2.0
 */
final class SuperShip_Checkout_Areas_REST_Controller {

	const REST_NAMESPACE = 'supership/v1';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/areas/provinces',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_provinces' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/areas/districts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_districts' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'province' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/areas/communes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_communes' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'province' => array( 'required' => true ),
					'district' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * GET /supership/v1/areas/provinces
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_provinces() {
		if ( ! class_exists( 'SuperShip_Address_Repository' ) ) {
			return self::unavailable();
		}

		$repo  = new SuperShip_Address_Repository();
		$items = array();

		foreach ( $repo->get_provinces() as $province ) {
			$items[] = array( 'name' => $province['name'] );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * GET /supership/v1/areas/districts?province={name}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_districts( WP_REST_Request $request ) {
		if ( ! class_exists( 'SuperShip_Address_Repository' ) ) {
			return self::unavailable();
		}

		$repo          = new SuperShip_Address_Repository();
		$province_name = sanitize_text_field( (string) $request->get_param( 'province' ) );

		$province_match = $repo->find_province_by_name( $province_name );

		if ( 'matched' !== $province_match['status'] ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}

		$items = array();
		foreach ( $repo->get_districts( $province_match['code'] ) as $district ) {
			$items[] = array( 'name' => $district['name'] );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * GET /supership/v1/areas/communes?province={name}&district={name}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_communes( WP_REST_Request $request ) {
		if ( ! class_exists( 'SuperShip_Address_Repository' ) ) {
			return self::unavailable();
		}

		$repo          = new SuperShip_Address_Repository();
		$province_name = sanitize_text_field( (string) $request->get_param( 'province' ) );
		$district_name = sanitize_text_field( (string) $request->get_param( 'district' ) );

		$province_match = $repo->find_province_by_name( $province_name );

		if ( 'matched' !== $province_match['status'] ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}

		$district_match = $repo->find_district_by_name( $province_match['code'], $district_name );

		if ( 'matched' !== $district_match['status'] ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}

		$items = array();
		foreach ( $repo->get_communes( $district_match['code'] ) as $commune ) {
			$items[] = array( 'name' => $commune['name'] );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error(
			'supership_unavailable',
			__( 'SuperShip address service is not available', 'supership-woocommerce' ),
			array( 'status' => 503 )
		);
	}
}
