<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Webhook_Controller {
	const NAMESPACE_NAME = 'spx-express/v1';
	const ROUTE = '/webhook/tracking';
	public static function init(): void { add_action( 'rest_api_init', array( __CLASS__, 'register' ) ); }
	public static function register(): void {
		register_rest_route( self::NAMESPACE_NAME, self::ROUTE, array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'receive' ), 'permission_callback' => '__return_true' ) );
	}
	public static function receive( WP_REST_Request $request ) {
		$raw = (string) $request->get_body();
		if ( '' === $raw || strlen( $raw ) > 262144 || false === strpos( strtolower( (string) $request->get_header( 'content-type' ) ), 'application/json' ) ) {
			return new WP_Error( 'spx_webhook_invalid', __( 'Invalid webhook request.', 'spx-express-woocommerce' ), array( 'status' => 400 ) );
		}
		json_decode( $raw, true ); if ( JSON_ERROR_NONE !== json_last_error() ) { return new WP_Error( 'spx_webhook_invalid_json', __( 'Invalid webhook request.', 'spx-express-woocommerce' ), array( 'status' => 400 ) ); }
		$verification = ( new SPX_Webhook_Verifier() )->verify( $raw, $request->get_headers() );
		if ( empty( $verification['verified'] ) ) {
			return new WP_Error( 'spx_webhook_disabled', __( 'SPX webhook verification is unavailable.', 'spx-express-woocommerce' ), array( 'status' => 503 ) );
		}
		return new WP_Error( 'spx_webhook_disabled', __( 'SPX webhook verification is unavailable.', 'spx-express-woocommerce' ), array( 'status' => 503 ) );
	}
}
