<?php
defined( 'ABSPATH' ) || exit;

/**
 * Queries SPX order status via batch_search_order, by tracking number or by
 * client order id (used for duplicate/unknown recovery). No WC_Order dependency,
 * no auto-retry, no sleeping. Never returns raw route text/PII — only status_code,
 * mapped labels and timestamps.
 */
final class SPX_Tracking_Service {
	const ENDPOINT = '/open/api/v1/order/batch_search_order';
	const NOT_FOUND = 13251;

	/** @var SPX_API_Config */          private $config;
	/** @var SPX_Http_Client_Interface */ private $client;

	public function __construct( SPX_API_Config $config = null, SPX_Http_Client_Interface $client = null ) {
		$this->config = $config ?: SPX_API_Config::for_test();
		$this->client = $client ?: new SPX_HTTP_Client( $this->config, new SPX_Request_Signer() );
	}

	public function get_by_tracking_number( string $tracking_no ): array {
		$tracking_no = trim( $tracking_no );
		if ( '' === $tracking_no ) { return $this->fail( 'missing_tracking', __( 'No tracking number provided.', 'spx-express-woocommerce' ) ); }
		return $this->query( array( 'tracking_no_list' => array( $tracking_no ) ), 'tracking_no', $tracking_no );
	}

	public function get_by_client_order_id( string $client_order_id ): array {
		$client_order_id = trim( $client_order_id );
		if ( '' === $client_order_id ) { return $this->fail( 'missing_order_id', __( 'No client order id provided.', 'spx-express-woocommerce' ) ); }
		return $this->query( array( 'order_id_list' => array( $client_order_id ) ), 'order_id', $client_order_id );
	}

	public function get_many_by_tracking_numbers( array $tracking_numbers ): array {
		$valid = array();
		foreach ( $tracking_numbers as $tracking ) {
			$tracking = strtoupper( trim( (string) $tracking ) );
			if ( self::is_real_tracking( $tracking ) ) { $valid[ $tracking ] = $tracking; }
		}
		$results = array();
		foreach ( array_chunk( array_values( $valid ), 100 ) as $chunk ) {
			$batch = $this->query_batch( $chunk );
			$results = array_merge( $results, $batch );
			if ( ! empty( $batch['_fatal'] ) ) { break; }
		}
		return $results;
	}

	private function query_batch( array $tracking_numbers ): array {
		if ( ! $this->config->has_account_credentials() ) { return array( '_fatal' => $this->fail( 'missing_credentials', __( 'SPX account credentials are not configured.', 'spx-express-woocommerce' ) ) ); }
		$body = array( 'user_id' => (int) $this->config->get_user_id(), 'user_secret' => $this->config->get_user_secret(), 'tracking_no_list' => $tracking_numbers );
		$response = $this->client->request( self::ENDPOINT, $body );
		if ( ! $response->is_success() ) { return array( '_fatal' => $this->fail( 'api_error', $response->get_message(), $response->get_ret_code(), $response->is_retryable() ) ); }
		$data = $response->get_data();
		$out = array();
		foreach ( (array) ( $data['orders'] ?? array() ) as $order ) {
			$safe = $this->normalise_order( $order );
			if ( $safe['found'] ) { $out[ $safe['tracking_no'] ] = $safe; }
		}
		foreach ( (array) ( $data['fail_list'] ?? array() ) as $failure ) {
			$key = strtoupper( trim( (string) ( $failure['tracking_no'] ?? '' ) ) );
			if ( $key ) { $out[ $key ] = array( 'success' => true, 'found' => false, 'ret_code' => (int) ( $failure['ret_code'] ?? 0 ), 'retryable' => self::NOT_FOUND === (int) ( $failure['ret_code'] ?? 0 ) ); }
		}
		foreach ( $tracking_numbers as $tracking ) { if ( ! isset( $out[ $tracking ] ) ) { $out[ $tracking ] = array( 'success' => true, 'found' => false, 'ret_code' => self::NOT_FOUND, 'retryable' => true ); } }
		return $out;
	}

	private function query( array $selector, string $match_field, string $match_value ): array {
		if ( ! $this->config->has_account_credentials() ) {
			return $this->fail( 'missing_credentials', __( 'SPX account credentials are not configured.', 'spx-express-woocommerce' ) );
		}
		$body     = array_merge( array( 'user_id' => (int) $this->config->get_user_id(), 'user_secret' => $this->config->get_user_secret() ), $selector );
		$response = $this->client->request( self::ENDPOINT, $body );

		if ( ! $response->is_success() ) {
			return $this->fail( 'api_error', $response->get_message(), $response->get_ret_code(), $response->is_retryable() );
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $this->fail( 'empty_data', __( 'SPX returned no tracking data.', 'spx-express-woocommerce' ) ); }

		// Not-found (eventual consistency right after create).
		foreach ( ( isset( $data['fail_list'] ) && is_array( $data['fail_list'] ) ? $data['fail_list'] : array() ) as $f ) {
			if ( self::NOT_FOUND === (int) ( $f['ret_code'] ?? 0 ) ) {
				return array( 'success' => true, 'found' => false, 'ret_code' => self::NOT_FOUND, 'message' => __( 'SPX has not synced this order yet.', 'spx-express-woocommerce' ) );
			}
		}

		$orders = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array();
		$match  = null;
		foreach ( $orders as $o ) {
			if ( (string) ( $o[ $match_field ] ?? '' ) === $match_value ) { $match = $o; break; }
		}
		if ( null === $match && 1 === count( $orders ) ) { $match = $orders[0]; }
		if ( null === $match ) {
			return array( 'success' => true, 'found' => false, 'ret_code' => null, 'message' => __( 'SPX has not synced this order yet.', 'spx-express-woocommerce' ) );
		}
		return $this->normalise_order( $match );
	}

	private function normalise_order( array $match ): array {
		$tracking_no = trim( (string) ( $match['tracking_no'] ?? '' ) );
		if ( ! self::is_real_tracking( $tracking_no ) ) {
			return array( 'success' => true, 'found' => false, 'ret_code' => null, 'message' => __( 'SPX has not returned a valid tracking number.', 'spx-express-woocommerce' ) );
		}

		$mapped = SPX_Tracking_Status_Mapper::map( $match['status_code'] ?? '', (string) ( $match['status'] ?? '' ) );
		return array(
			'success'         => true,
			'found'           => true,
			'tracking_no'     => $tracking_no,
			'order_id'        => (string) ( $match['order_id'] ?? '' ),
			'tracking_link'   => self::safe_https( (string) ( $match['tracking_link'] ?? '' ) ),
			'status_code'     => $mapped['status_code'],
			'official_status' => $mapped['official_status'],
			'internal_status' => $mapped['internal_status'],
			'customer_label'  => $mapped['customer_label'],
			'terminal'        => $mapped['terminal'],
			'routes'          => self::safe_routes( isset( $match['routes'] ) && is_array( $match['routes'] ) ? $match['routes'] : array() ),
			'edd_min'         => self::safe_edd( $match['edd_info'] ?? array(), array( 'edd_min', 'edt_min', 'estimated_delivery_min' ) ),
			'edd_max'         => self::safe_edd( $match['edd_info'] ?? array(), array( 'edd_max', 'edt_max', 'estimated_delivery_max' ) ),
		);
	}

	/** Only status_code + mapped label + timestamp; never raw route message/PII. */
	private static function safe_routes( array $routes ): array {
		$out = array();
		foreach ( $routes as $r ) {
			$m     = SPX_Tracking_Status_Mapper::map( $r['status_code'] ?? '', (string) ( $r['status'] ?? '' ) );
			$out[] = array(
				'status'         => substr( sanitize_text_field( (string) ( $r['status'] ?? '' ) ), 0, 100 ),
				'status_code'    => $m['status_code'],
				'customer_label' => $m['customer_label'],
				'customer_message' => self::safe_route_message( (string) ( $r['message'] ?? '' ), $m['customer_label'] ),
				'timestamp'      => isset( $r['timestamp'] ) && is_numeric( $r['timestamp'] ) ? (int) $r['timestamp'] : 0,
			);
		}
		return $out;
	}

	private static function safe_route_message( string $message, string $fallback ): string {
		$message = trim( strip_tags( $message ) );
		if ( '' === $message || preg_match( '/(?:\+?84|0)\d{8,10}|(?:debug|exception|stack|secret|signature|token|phone|address|địa chỉ|số điện thoại|ret_code|trace)/iu', $message ) ) { return $fallback; }
		return substr( sanitize_text_field( $message ), 0, 500 );
	}

	private static function safe_edd( $edd, array $keys ): string {
		if ( ! is_array( $edd ) ) { return ''; }
		foreach ( $keys as $key ) { if ( isset( $edd[ $key ] ) && ( is_scalar( $edd[ $key ] ) ) ) { return substr( sanitize_text_field( (string) $edd[ $key ] ), 0, 40 ); } }
		return '';
	}

	private static function safe_https( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) { return ''; }
		return ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) ? $url : '';
	}

	public static function is_real_tracking( string $tracking_no ): bool {
		if ( 0 === stripos( $tracking_no, 'SPXMOCK' ) ) { return false; }
		return (bool) preg_match( '/^SPX[A-Z0-9]{6,37}$/i', $tracking_no );
	}

	private function fail( string $code, string $message, $ret_code = null, bool $retryable = false ): array {
		return array( 'success' => false, 'found' => false, 'error_code' => $code, 'ret_code' => $ret_code, 'message' => $message, 'retryable' => $retryable );
	}
}
