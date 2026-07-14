<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Label_Manager {
	const CACHE_TTL = 1500; // 25 minutes; below the documented 30-minute link lifetime.
	/** @var object|null */ private $service;
	/** @var string[] */ private $allowed_hosts;

	public function __construct( $service = null, array $allowed_hosts = array() ) {
		$this->service = $service;
		$this->allowed_hosts = $allowed_hosts ?: array( 'spx.vn', 'test-stable.spx.vn', 'uat.spx.vn' );
	}

	public function request_for_order( WC_Order $order ): array {
		$tracking = strtoupper( trim( (string) $order->get_meta( '_spx_tracking_number', true ) ) );
		if ( ! SPX_Tracking_Service::is_real_tracking( $tracking ) ) { return self::fail( 'invalid_tracking', __( 'This order has no valid SPX tracking number.', 'spx-express-woocommerce' ) ); }
		if ( 'test' !== (string) $order->get_meta( '_spx_shipment_environment', true ) ) { return self::fail( 'environment_mismatch', __( 'Shipping labels are available only for sandbox shipments.', 'spx-express-woocommerce' ) ); }
		if ( 'cancelled' === (string) $order->get_meta( '_spx_shipment_status', true ) ) { return self::fail( 'status_ineligible', __( 'A label is not available for a cancelled shipment.', 'spx-express-woocommerce' ) ); }
		$cached = $this->cached_for_order( $order->get_id() );
		if ( $cached ) { return array_merge( array( 'success' => true ), $cached ); }
		$service = $this->service ?: new SPX_Label_Service();
		$results = $service->get_shipping_labels( array( $tracking ) );
		$result = $results[ $tracking ] ?? self::fail( 'missing_result', __( 'SPX returned no matching label result.', 'spx-express-woocommerce' ) );
		$order->update_meta_data( '_spx_label_last_requested_at', gmdate( 'c' ) );
		if ( empty( $result['success'] ) ) {
			$order->update_meta_data( '_spx_label_last_result', sanitize_key( (string) ( $result['error_code'] ?? 'failed' ) ) ); $order->save(); return $result;
		}
		return $this->store_verified_result( $order, (string) $result['awb_link'], (string) ( $result['format'] ?? 'unknown' ) );
	}

	/** Store only a validated short-lived cache entry; the URL is never written to order meta. */
	public function store_verified_result( WC_Order $order, string $url, string $format = 'unknown' ): array {
		$validated = $this->validate_url( $url );
		if ( empty( $validated['valid'] ) ) { $order->update_meta_data( '_spx_label_last_result', 'unsafe_url' ); $order->save(); return self::fail( 'unsafe_url', __( 'SPX returned a label URL that is not allowed.', 'spx-express-woocommerce' ) ); }
		$format = sanitize_key( $format ) ?: 'unknown';
		$order->update_meta_data( '_spx_label_last_requested_at', gmdate( 'c' ) ); $order->update_meta_data( '_spx_label_last_result', 'succeeded' ); $order->update_meta_data( '_spx_label_format', $format ); $order->save();
		$cached = array( 'url' => $validated['url'], 'expires_at' => time() + self::CACHE_TTL, 'format' => $format ); set_transient( self::cache_key( $order->get_id() ), $cached, self::CACHE_TTL );
		return array_merge( array( 'success' => true ), $cached );
	}

	public function cached_for_order( int $order_id ): array {
		$value = get_transient( self::cache_key( $order_id ) );
		if ( ! is_array( $value ) || empty( $value['url'] ) || (int) ( $value['expires_at'] ?? 0 ) <= time() ) { return array(); }
		$valid = $this->validate_url( (string) $value['url'] ); return $valid['valid'] ? $value : array();
	}

	public function validate_url( string $url ): array {
		$url = trim( $url ); $parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) { return array( 'valid' => false ); }
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || ! in_array( $host, array_map( 'strtolower', $this->allowed_hosts ), true ) ) { return array( 'valid' => false ); }
		return array( 'valid' => true, 'url' => $url, 'host' => $host );
	}

	private static function cache_key( int $order_id ): string { return 'spx_label_' . get_current_user_id() . '_' . $order_id; }
	private static function fail( string $code, string $message ): array { return array( 'success' => false, 'error_code' => $code, 'message' => sanitize_text_field( $message ) ); }
}
