<?php
defined( 'ABSPATH' ) || exit;

/** Public, read-only, local-dataset endpoints for cascading checkout selects. */
final class SPX_Checkout_Address_REST_Controller {
	const NAMESPACE = 'spx-express/v1';

	public static function init(): void { add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) ); }

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/provinces', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'provinces' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/districts', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'districts' ),
			'permission_callback' => '__return_true',
			'args' => array( 'province_id' => self::parent_arg( 'p' ) ),
		) );
		register_rest_route( self::NAMESPACE, '/wards', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'wards' ),
			'permission_callback' => '__return_true',
			'args' => array( 'province_id' => self::parent_arg( 'p' ), 'district_id' => self::parent_arg( 'd' ) ),
		) );
	}

	public static function provinces(): WP_REST_Response {
		$service = new SPX_Checkout_Address_Service();
		return rest_ensure_response( self::cached( 'provinces', $service, function () use ( $service ) { return $service->get_provinces(); } ) );
	}

	public static function districts( WP_REST_Request $request ): WP_REST_Response {
		$service = new SPX_Checkout_Address_Service();
		$id = (string) $request->get_param( 'province_id' );
		return rest_ensure_response( self::cached( 'districts_' . $id, $service, function () use ( $service, $id ) { return $service->get_districts( $id ); } ) );
	}

	public static function wards( WP_REST_Request $request ): WP_REST_Response {
		$service = new SPX_Checkout_Address_Service();
		$pid = (string) $request->get_param( 'province_id' );
		$did = (string) $request->get_param( 'district_id' );
		return rest_ensure_response( self::cached( 'wards_' . $pid . '_' . $did, $service, function () use ( $service, $pid, $did ) { return $service->get_wards( $pid, $did ); } ) );
	}

	private static function parent_arg( string $prefix ): array {
		return array(
			'required' => true,
			'type' => 'string',
			'maxLength' => 14,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => function ( $value ) use ( $prefix ) { return 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '_[a-f0-9]{12}$/', (string) $value ); },
		);
	}

	private static function cached( string $key, SPX_Checkout_Address_Service $service, callable $loader ): array {
		$version = $service->get_dataset_version();
		$cache_key = 'spx_checkout_' . md5( $version . '|' . $key );
		$items = get_transient( $cache_key );
		if ( false === $items ) { $items = $loader(); set_transient( $cache_key, $items, HOUR_IN_SECONDS ); }
		return array( 'dataset_version' => $version, 'items' => $items );
	}
}
