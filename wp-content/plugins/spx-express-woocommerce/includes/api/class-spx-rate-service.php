<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a single-order SPX shipping-fee check via batch_check_order:
 * local service-area validation → request mapping → signed HTTP call → response
 * parsing → normalised quote. No WC_Order dependency, no order-meta writes, no
 * HTML. Never auto-retries. Never returns credentials or raw response.
 */
final class SPX_Rate_Service {
	const ENDPOINT     = '/open/api/v1/order/batch_check_order';
	const MAX_COD_VND  = 20000000;
	const MAX_GRAMS    = 15000; // 15 kg for the fee API

	/** @var SPX_API_Config */          private $config;
	/** @var SPX_Http_Client_Interface */ private $client;
	/** @var SPX_Address_Repository */    private $repo;

	public function __construct( SPX_API_Config $config = null, SPX_Http_Client_Interface $client = null, SPX_Address_Repository $repo = null ) {
		$this->config = $config ?: SPX_API_Config::for_test();
		$this->client = $client ?: new SPX_HTTP_Client( $this->config, new SPX_Request_Signer() );
		$this->repo   = $repo ?: new SPX_Address_Repository();
	}

	/** @return array quote contract (success or failure), never with secrets. */
	public function calculate( array $shipment ): array {
		$s = $this->normalise_shipment( $shipment );

		$err = $this->validate_local( $s );
		if ( null !== $err ) {
			return $this->fail( $err['code'], null, $err['message'], false );
		}
		if ( ! $this->config->has_account_credentials() ) {
			return $this->fail( 'missing_credentials', null, __( 'SPX account credentials are not configured.', 'spx-express-woocommerce' ), false );
		}

		$order = SPX_Rate_Request_Mapper::map_order( $s );
		$body  = array(
			'user_id'     => (int) $this->config->get_user_id(),
			'user_secret' => $this->config->get_user_secret(),
			'orders'      => array( $order ),
		);

		$response = $this->client->request( self::ENDPOINT, $body );
		return $this->parse( $response );
	}

	/* ---- local validation ------------------------------------------------ */

	private function validate_local( array $s ): ?array {
		$sender = $s['sender'];
		$rcpt   = $s['recipient'];
		$need   = function ( $a, $k ) { return '' === trim( (string) ( $a[ $k ] ?? '' ) ); };

		foreach ( array( 'name', 'phone', 'address', 'province_code', 'district_code', 'ward_code' ) as $k ) {
			if ( $need( $sender, $k ) ) { return self::v( 'sender_incomplete', __( 'Sender profile is incomplete.', 'spx-express-woocommerce' ) ); }
		}
		foreach ( array( 'name', 'phone', 'address', 'province_code', 'district_code', 'ward_code' ) as $k ) {
			if ( $need( $rcpt, $k ) ) { return self::v( 'recipient_unresolved', __( 'Recipient SPX address is not fully resolved.', 'spx-express-woocommerce' ) ); }
		}

		$grams = (int) $s['weight_grams'];
		if ( $grams < 1 ) { return self::v( 'weight_invalid', __( 'Parcel weight must be greater than zero.', 'spx-express-woocommerce' ) ); }
		if ( $grams > self::MAX_GRAMS ) { return self::v( 'weight_exceeded', __( 'Parcel weight exceeds the 15 kg rate limit.', 'spx-express-woocommerce' ) ); }
		if ( (int) $s['parcel_item_quantity'] < 1 ) { return self::v( 'quantity_invalid', __( 'Parcel item quantity must be at least one.', 'spx-express-woocommerce' ) ); }

		if ( ! empty( $s['is_cod'] ) ) {
			$amt = $s['cod_amount'];
			if ( ! is_int( $amt ) || $amt < 0 ) { return self::v( 'cod_invalid', __( 'COD amount must be a non-negative whole number.', 'spx-express-woocommerce' ) ); }
			if ( $amt > self::MAX_COD_VND ) { return self::v( 'cod_exceeded', __( 'COD amount exceeds 20,000,000 VND.', 'spx-express-woocommerce' ) ); }
		}

		// Service-area capability (recipient must be deliverable/available).
		if ( $this->repo->is_available() ) {
			$rw = $this->repo->get_ward( $rcpt['district_code'], $rcpt['ward_code'] );
			if ( null === $rw ) { return self::v( 'recipient_area_unknown', __( 'The recipient area is not in the SPX dataset.', 'spx-express-woocommerce' ) ); }
			if ( 'Available' !== ( $rw['status'] ?? '' ) ) { return self::v( 'recipient_area_unavailable', __( 'The recipient area is not currently serviceable by SPX.', 'spx-express-woocommerce' ) ); }
			if ( empty( $rw['delivery'] ) ) { return self::v( 'recipient_no_delivery', __( 'SPX does not deliver to the recipient area.', 'spx-express-woocommerce' ) ); }
			if ( ! empty( $s['is_cod'] ) && empty( $rw['cod'] ) ) { return self::v( 'recipient_no_cod', __( 'SPX does not support COD for the recipient area.', 'spx-express-woocommerce' ) ); }

			if ( 1 === (int) $s['collect_type'] ) { // pickup
				$sw = $this->repo->get_ward( $sender['district_code'], $sender['ward_code'] );
				if ( null === $sw || empty( $sw['pickup'] ) ) { return self::v( 'sender_no_pickup', __( 'SPX pickup is not available at the sender area.', 'spx-express-woocommerce' ) ); }
			}
		}
		return null;
	}

	/* ---- response parsing ------------------------------------------------ */

	private function parse( SPX_API_Response $response ): array {
		if ( ! $response->is_success() ) {
			return $this->fail( 'api_error', $response->get_ret_code(), $response->get_message(), $response->is_retryable() );
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $this->fail( 'empty_data', 0, __( 'SPX returned no rate data.', 'spx-express-woocommerce' ), false );
		}
		$fail_list = isset( $data['fail_list'] ) && is_array( $data['fail_list'] ) ? $data['fail_list'] : array();
		if ( ! empty( $fail_list ) ) {
			$first = $fail_list[0];
			$rc    = isset( $first['ret_code'] ) ? (int) $first['ret_code'] : 0;
			$msg   = $rc ? SPX_API_Error_Mapper::safe_message( $rc ) : __( 'SPX could not price this order.', 'spx-express-woocommerce' );
			return $this->fail( 'order_rejected', $rc, $msg, $rc ? SPX_API_Error_Mapper::is_retryable( $rc ) : false );
		}
		$orders = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array();
		if ( empty( $orders ) ) {
			return $this->fail( 'empty_orders', 0, __( 'SPX returned no priced order.', 'spx-express-woocommerce' ), false );
		}

		$o   = $orders[0];
		$esf = self::fee( $o, 'estimated_shipping_fee' );
		if ( null === $esf ) {
			return $this->fail( 'invalid_fee', 0, __( 'SPX returned an invalid shipping fee.', 'spx-express-woocommerce' ), false );
		}

		$quote = array(
			'success'                => true,
			'estimated_shipping_fee' => $esf,
			// Gate A finding: SPX docs do NOT state the fee currency/unit. Values are
			// kept RAW (unscaled); the unit is unconfirmed, so never assume VND and
			// never format with wc_price(). COD amount is separately documented as VND.
			'currency'               => '',
			'fee_unit'               => 'unconfirmed',
			'checked_at'             => gmdate( 'c' ),
			'environment'            => $this->config->get_environment(),
		);
		foreach ( array( 'basic_shipping_fee', 'cod_service_fee', 'high_value_processing_fee', 'vat_fee', 'voucher_shipping_fee' ) as $k ) {
			$v = self::fee( $o, $k );
			if ( null !== $v ) { $quote[ $k ] = $v; }
		}
		foreach ( array( 'edt_min', 'edt_max' ) as $k ) {
			if ( isset( $o[ $k ] ) && is_numeric( $o[ $k ] ) ) { $quote[ $k ] = (int) $o[ $k ]; }
		}
		return $quote;
	}

	/** Numeric, non-negative fee; distinguishes missing/non-numeric/negative (=> null). */
	private static function fee( array $o, string $key ) {
		if ( ! array_key_exists( $key, $o ) || null === $o[ $key ] || ! is_numeric( $o[ $key ] ) ) { return null; }
		$v = 0 + $o[ $key ];
		if ( $v < 0 ) { return null; }
		return ( (float) $v == (int) $v ) ? (int) $v : (float) $v;
	}

	/* ---- shipment normalisation ------------------------------------------ */

	private function normalise_shipment( array $shipment ): array {
		$items = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : array();
		$qty   = 0;
		$name  = '';
		foreach ( $items as $it ) {
			$qty += max( 0, (int) ( $it['quantity'] ?? 0 ) );
			if ( '' === $name && ! empty( $it['name'] ) ) { $name = (string) $it['name']; }
		}
		$declared = max( 0, (int) round( (float) ( $shipment['declared_value'] ?? 0 ) ) );
		return array(
			'sender'               => isset( $shipment['sender'] ) && is_array( $shipment['sender'] ) ? $shipment['sender'] : array(),
			'recipient'            => isset( $shipment['recipient'] ) && is_array( $shipment['recipient'] ) ? $shipment['recipient'] : array(),
			'weight_grams'         => max( 0, (int) ( $shipment['weight_grams'] ?? 0 ) ),
			'is_cod'               => ! empty( $shipment['is_cod'] ),
			'cod_amount'           => (int) round( (float) ( $shipment['cod_amount'] ?? 0 ) ),
			'insured_value'        => $declared,
			'service_type'         => (int) ( $shipment['service_type'] ?? 1 ),
			'collect_type'         => (int) ( $shipment['collect_type'] ?? 2 ),
			'parcel_item_name'     => $name,
			'parcel_item_quantity' => max( 1, $qty ),
			'length_cm'            => $shipment['length_cm'] ?? 0,
			'width_cm'             => $shipment['width_cm'] ?? 0,
			'height_cm'            => $shipment['height_cm'] ?? 0,
		);
	}

	private static function v( string $code, string $message ): array { return array( 'code' => $code, 'message' => $message ); }

	private function fail( string $code, $ret_code, string $message, bool $retryable ): array {
		return array(
			'success'   => false,
			'error_code'=> $code,
			'ret_code'  => $ret_code,
			'message'   => $message,
			'retryable' => $retryable,
		);
	}
}
