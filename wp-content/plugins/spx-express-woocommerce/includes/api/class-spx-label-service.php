<?php
defined( 'ABSPATH' ) || exit;

/** Fetches short-lived SPX label links. It never downloads or persists a link. */
final class SPX_Label_Service {
	const ENDPOINT = '/open/api/v1/order/batch_get_shipping_label';
	const MAX_BATCH = 30; // Fail-safe: official page conflicts between 30 and 100.
	/** @var SPX_API_Config */ private $config;
	/** @var SPX_Http_Client_Interface */ private $client;

	public function __construct( SPX_API_Config $config = null, SPX_Http_Client_Interface $client = null ) {
		$this->config = $config ?: SPX_API_Config::for_test();
		$this->client = $client ?: new SPX_HTTP_Client( $this->config, new SPX_Request_Signer() );
	}

	/** @return array<string,array> Results keyed by validated tracking number. */
	public function get_shipping_labels( array $tracking_numbers ): array {
		$valid = array();
		foreach ( $tracking_numbers as $tracking ) {
			$tracking = strtoupper( trim( (string) $tracking ) );
			if ( SPX_Tracking_Service::is_real_tracking( $tracking ) ) { $valid[ $tracking ] = $tracking; }
		}
		if ( ! $valid ) { return array(); }
		$out = array();
		foreach ( array_chunk( array_values( $valid ), self::MAX_BATCH ) as $chunk ) { $out = array_merge( $out, $this->request_chunk( $chunk ) ); }
		return $out;
	}

	private function request_chunk( array $tracking_numbers ): array {
		if ( SPX_API_Config::TEST_ENV !== $this->config->get_environment() || ! $this->config->has_account_credentials() ) { return $this->fail_all( $tracking_numbers, 'configuration_error', null, false ); }
		$response = $this->client->request( self::ENDPOINT, array( 'user_id' => (int) $this->config->get_user_id(), 'user_secret' => $this->config->get_user_secret(), 'tracking_no_list' => $tracking_numbers ) );
		if ( ! $response->is_success() ) { return $this->fail_all( $tracking_numbers, 'api_error', $response->get_ret_code(), $response->is_retryable() ); }
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $this->fail_all( $tracking_numbers, 'malformed_response', null, false ); }

		$out = array();
		foreach ( (array) ( $data['fail_list'] ?? array() ) as $failure ) {
			$tracking = strtoupper( trim( (string) ( $failure['tracking_no'] ?? '' ) ) );
			if ( ! isset( array_flip( $tracking_numbers )[ $tracking ] ) ) { continue; }
			$ret = (int) ( $failure['ret_code'] ?? 0 );
			$out[ $tracking ] = array( 'success' => false, 'error_code' => 'label_rejected', 'ret_code' => $ret, 'message' => $ret ? SPX_API_Error_Mapper::safe_message( $ret ) : __( 'SPX could not create the shipping label.', 'spx-express-woocommerce' ), 'retryable' => $ret ? SPX_API_Error_Mapper::is_retryable( $ret ) : false );
		}
		$link = trim( (string) ( $data['awb_link'] ?? '' ) );
		foreach ( $tracking_numbers as $tracking ) {
			if ( isset( $out[ $tracking ] ) ) { continue; }
			if ( '' === $link ) { $out[ $tracking ] = array( 'success' => false, 'error_code' => 'missing_awb_link', 'ret_code' => null, 'message' => __( 'SPX did not return a label link.', 'spx-express-woocommerce' ), 'retryable' => false ); continue; }
			$out[ $tracking ] = array( 'success' => true, 'tracking_no' => $tracking, 'awb_link' => $link, 'format' => self::format_from_url( $link ) );
		}
		return $out;
	}

	private function fail_all( array $trackings, string $code, $ret_code, bool $retryable ): array {
		$out = array(); foreach ( $trackings as $tracking ) { $out[ $tracking ] = array( 'success' => false, 'error_code' => $code, 'ret_code' => $ret_code, 'message' => $ret_code ? SPX_API_Error_Mapper::safe_message( (int) $ret_code ) : __( 'The SPX label request could not be completed.', 'spx-express-woocommerce' ), 'retryable' => $retryable ); } return $out;
	}

	private static function format_from_url( string $url ): string {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return '.pdf' === substr( $path, -4 ) ? 'pdf' : 'unknown';
	}
}
