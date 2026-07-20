<?php
defined( 'ABSPATH' ) || exit;

/**
 * Creates a single SPX shipment via batch_create_order. Side-effecting: it calls
 * the endpoint AT MOST ONCE and never auto-retries. Distinguishes created /
 * duplicate (13103) / failed (business validation) / unknown (timeout, 5xx, or
 * ambiguous) so the caller can recover safely. No WC_Order writes, no HTML,
 * no raw response/secrets returned. Fees are kept RAW (unit unconfirmed, Gate A).
 */
final class SPX_Shipment_Service {
	const ENDPOINT    = '/open/api/v1/order/batch_create_order';
	const MAX_COD_VND = 20000000;
	const MAX_GRAMS   = 17000; // 17 kg create limit

	/** @var SPX_API_Config */          private $config;
	/** @var SPX_Http_Client_Interface */ private $client;
	/** @var SPX_Address_Repository */    private $repo;

	public function __construct( SPX_API_Config $config = null, SPX_Http_Client_Interface $client = null, SPX_Address_Repository $repo = null ) {
		$this->config = $config ?: SPX_API_Config::for_test();
		$this->client = $client ?: new SPX_HTTP_Client( $this->config, new SPX_Request_Signer() );
		$this->repo   = $repo ?: new SPX_Address_Repository();
	}

	public function create( array $shipment ): array {
		$s = $this->normalise( $shipment );

		if ( '' === $s['client_order_id'] || strlen( $s['client_order_id'] ) > SPX_Client_Order_ID::MAX_LEN || ! preg_match( '/^[A-Za-z0-9_-]+$/', $s['client_order_id'] ) ) {
			return $this->fail( 'failed', 'invalid_client_order_id', null, __( 'Invalid SPX client order id.', 'spx-express-woocommerce' ) );
		}
		$err = $this->validate_local( $s );
		if ( null !== $err ) { return $this->fail( 'failed', $err['code'], null, $err['message'] ); }
		if ( ! $this->config->has_account_credentials() ) {
			return $this->fail( 'failed', 'missing_credentials', null, __( 'SPX account credentials are not configured.', 'spx-express-woocommerce' ) );
		}

		$order = SPX_Create_Request_Mapper::map_order( $s );
		$body  = array(
			'user_id'     => (int) $this->config->get_user_id(),
			'user_secret' => $this->config->get_user_secret(),
			'orders'      => array( $order ),
		);

		$response = $this->client->request( self::ENDPOINT, $body ); // exactly once
		return $this->parse( $response, $s['client_order_id'] );
	}

	/* ---- validation ------------------------------------------------------ */

	private function validate_local( array $s ): ?array {
		if ( true !== $s['ready_for_shipment'] ) {
			$allowed = array( 'payment_method_missing', 'payment_not_confirmed', 'order_status_blocked', 'invalid_order_total' );
			$reason  = in_array( $s['payment_block_reason'], $allowed, true ) ? $s['payment_block_reason'] : 'payment_not_confirmed';
			return self::v( $reason, __( 'WooCommerce payment is not ready for SPX shipment creation.', 'spx-express-woocommerce' ) );
		}
		$sender = $s['sender'];
		$rcpt   = $s['recipient'];
		$need   = function ( $a, $k ) { return '' === trim( (string) ( $a[ $k ] ?? '' ) ); };

		foreach ( array( 'name', 'phone', 'address', 'province_code', 'district_code', 'ward_code' ) as $k ) {
			if ( $need( $sender, $k ) ) { return self::v( 'sender_incomplete', __( 'Sender profile is incomplete.', 'spx-express-woocommerce' ) ); }
			if ( $need( $rcpt, $k ) ) { return self::v( 'recipient_unresolved', __( 'Recipient SPX address is not fully resolved.', 'spx-express-woocommerce' ) ); }
		}
		$grams = (int) $s['weight_grams'];
		if ( $grams < 1 ) { return self::v( 'weight_invalid', __( 'Parcel weight must be greater than zero.', 'spx-express-woocommerce' ) ); }
		if ( $grams > self::MAX_GRAMS ) { return self::v( 'weight_exceeded', __( 'Parcel weight exceeds the 17 kg create limit.', 'spx-express-woocommerce' ) ); }
		if ( (int) $s['parcel_item_quantity'] < 1 ) { return self::v( 'quantity_invalid', __( 'Parcel item quantity must be at least one.', 'spx-express-woocommerce' ) ); }
		if ( ! empty( $s['is_cod'] ) ) {
			$amt = $s['cod_amount'];
			if ( ! is_int( $amt ) || $amt < 0 ) { return self::v( 'cod_invalid', __( 'COD amount must be a non-negative whole number.', 'spx-express-woocommerce' ) ); }
			if ( $amt > self::MAX_COD_VND ) { return self::v( 'cod_exceeded', __( 'COD amount exceeds 20,000,000 VND.', 'spx-express-woocommerce' ) ); }
		}
		if ( 1 === (int) $s['collect_type'] && empty( $s['pickup_time'] ) ) {
			return self::v( 'pickup_time_missing', __( 'A pickup timeslot is required for pickup collection.', 'spx-express-woocommerce' ) );
		}
		if ( $this->repo->is_available() ) {
			$rw = $this->repo->get_ward( $rcpt['district_code'], $rcpt['ward_code'] );
			if ( null === $rw ) { return self::v( 'recipient_area_unknown', __( 'The recipient area is not in the SPX dataset.', 'spx-express-woocommerce' ) ); }
			if ( 'Available' !== ( $rw['status'] ?? '' ) ) { return self::v( 'recipient_area_unavailable', __( 'The recipient area is not currently serviceable by SPX.', 'spx-express-woocommerce' ) ); }
			if ( empty( $rw['delivery'] ) ) { return self::v( 'recipient_no_delivery', __( 'SPX does not deliver to the recipient area.', 'spx-express-woocommerce' ) ); }
			if ( ! empty( $s['is_cod'] ) && empty( $rw['cod'] ) ) { return self::v( 'recipient_no_cod', __( 'SPX does not support COD for the recipient area.', 'spx-express-woocommerce' ) ); }
			if ( 1 === (int) $s['collect_type'] ) {
				$sw = $this->repo->get_ward( $sender['district_code'], $sender['ward_code'] );
				if ( null === $sw || empty( $sw['pickup'] ) ) { return self::v( 'sender_no_pickup', __( 'SPX pickup is not available at the sender area.', 'spx-express-woocommerce' ) ); }
			}
		}
		return null;
	}

	/* ---- response parsing (state machine) -------------------------------- */

	private function parse( SPX_API_Response $response, string $client_order_id ): array {
		if ( ! $response->is_success() ) {
			// Transport/5xx/temporary => UNKNOWN (may or may not have been created).
			if ( SPX_API_Error_Mapper::CAT_TRANSPORT === $response->get_category() || $response->get_http_status() >= 500 || $response->is_retryable() ) {
				return $this->unknown( $client_order_id, __( 'SPX did not confirm whether the shipment was created.', 'spx-express-woocommerce' ) );
			}
			return $this->fail( 'failed', 'api_error', $response->get_ret_code(), $response->get_message() );
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $this->unknown( $client_order_id, __( 'SPX returned no create data.', 'spx-express-woocommerce' ) );
		}

		// Per-order failure?
		foreach ( ( isset( $data['fail_list'] ) && is_array( $data['fail_list'] ) ? $data['fail_list'] : array() ) as $f ) {
			if ( (string) ( $f['order_id'] ?? '' ) !== $client_order_id && isset( $data['fail_list'][1] ) ) { continue; }
			$rc = isset( $f['ret_code'] ) ? (int) $f['ret_code'] : 0;
			if ( 13103 === $rc ) {
				return array( 'success' => false, 'state' => 'duplicate', 'order_id' => $client_order_id, 'ret_code' => 13103, 'message' => __( 'SPX reports this order was already created (duplicate).', 'spx-express-woocommerce' ) );
			}
			return $this->fail( 'failed', 'order_rejected', $rc, $rc ? SPX_API_Error_Mapper::safe_message( $rc ) : __( 'SPX could not create this order.', 'spx-express-woocommerce' ) );
		}

		$orders = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array();
		$match  = null;
		foreach ( $orders as $o ) {
			if ( (string) ( $o['order_id'] ?? '' ) === $client_order_id ) { $match = $o; break; }
		}
		if ( null === $match && 1 === count( $orders ) ) { $match = $orders[0]; }
		if ( null === $match ) {
			return $this->unknown( $client_order_id, __( 'SPX did not return a matching created order.', 'spx-express-woocommerce' ) );
		}

		$tracking = trim( (string) ( $match['tracking_no'] ?? '' ) );
		if ( '' === $tracking || ! self::is_real_tracking( $tracking ) ) {
			return $this->unknown( $client_order_id, __( 'SPX did not return a valid tracking number.', 'spx-express-woocommerce' ) );
		}

		return array(
			'success'       => true,
			'state'         => 'created',
			'order_id'      => $client_order_id,
			'tracking_no'   => $tracking,
			'tracking_link' => self::safe_https( (string) ( $match['tracking_link'] ?? '' ) ),
			'status'        => 'created',
			'status_code'   => '1001',
			'environment'   => $this->config->get_environment(),
			'created_at'    => gmdate( 'c' ),
			'fees'          => self::raw_fees( $match ),
		);
	}

	/** A real SPX tracking number: starts with SPX, alphanumeric, and NOT a mock code. */
	public static function is_real_tracking( string $t ): bool {
		if ( 0 === stripos( $t, 'SPXMOCK' ) ) { return false; }
		return (bool) preg_match( '/^SPX[A-Z0-9]{6,37}$/i', $t );
	}

	private static function safe_https( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) { return ''; }
		return ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) ? $url : '';
	}

	/** Raw fee snapshot (unit unconfirmed — never scaled, never wc_price). */
	private static function raw_fees( array $o ): array {
		$fees = array( 'fee_unit' => 'unconfirmed', 'currency' => '' );
		foreach ( array( 'estimated_shipping_fee', 'basic_shipping_fee', 'cod_service_fee', 'high_value_processing_fee', 'vat_fee', 'voucher_shipping_fee' ) as $k ) {
			if ( isset( $o[ $k ] ) && is_numeric( $o[ $k ] ) ) { $fees[ $k ] = 0 + $o[ $k ]; }
		}
		return $fees;
	}

	/* ---- normalisation --------------------------------------------------- */

	private function normalise( array $shipment ): array {
		$items = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : array();
		$qty   = 0; $name = '';
		foreach ( $items as $it ) {
			$qty += max( 0, (int) ( $it['quantity'] ?? 0 ) );
			if ( '' === $name && ! empty( $it['name'] ) ) { $name = (string) $it['name']; }
		}
		$recipient = isset( $shipment['recipient'] ) && is_array( $shipment['recipient'] ) ? $shipment['recipient'] : array();
		if ( ! empty( $shipment['note'] ) && empty( $recipient['instruction'] ) ) { $recipient['instruction'] = (string) $shipment['note']; }

		return array(
			'client_order_id'      => trim( (string) ( $shipment['client_order_id'] ?? '' ) ),
			'sender'               => isset( $shipment['sender'] ) && is_array( $shipment['sender'] ) ? $shipment['sender'] : array(),
			'recipient'            => $recipient,
			'weight_grams'         => max( 0, (int) ( $shipment['weight_grams'] ?? 0 ) ),
			'payment_state'        => sanitize_key( (string) ( $shipment['payment_state'] ?? '' ) ),
			'ready_for_shipment'   => true === ( $shipment['ready_for_shipment'] ?? false ),
			'payment_block_reason' => sanitize_key( (string) ( $shipment['payment_block_reason'] ?? '' ) ),
			'is_cod'               => 'cash_on_delivery' === (string) ( $shipment['payment_state'] ?? '' ) && ! empty( $shipment['is_cod'] ),
			'cod_amount'           => array_key_exists( 'cod_amount', $shipment ) ? $shipment['cod_amount'] : null,
			'insured_value'        => max( 0, (int) round( (float) ( $shipment['declared_value'] ?? 0 ) ) ),
			'service_type'         => (int) ( $shipment['service_type'] ?? 1 ),
			'payment_role'         => (int) ( $shipment['payment_role'] ?? 1 ),
			'collect_type'         => (int) ( $shipment['collect_type'] ?? 2 ),
			'pickup_time'          => (int) ( $shipment['pickup_time'] ?? 0 ),
			'pickup_time_range_id' => (int) ( $shipment['pickup_time_range_id'] ?? 0 ),
			'pickup_time_range'    => (string) ( $shipment['pickup_time_range'] ?? '' ),
			'parcel_item_name'     => $name,
			'parcel_item_quantity' => max( 1, $qty ),
			'length_cm'            => $shipment['length_cm'] ?? 0,
			'width_cm'             => $shipment['width_cm'] ?? 0,
			'height_cm'            => $shipment['height_cm'] ?? 0,
		);
	}

	private static function v( string $code, string $message ): array { return array( 'code' => $code, 'message' => $message ); }

	private function unknown( string $client_order_id, string $message ): array {
		return array( 'success' => false, 'state' => 'unknown', 'order_id' => $client_order_id, 'ret_code' => null, 'message' => $message );
	}

	private function fail( string $state, string $code, $ret_code, string $message ): array {
		return array( 'success' => false, 'state' => $state, 'error_code' => $code, 'ret_code' => $ret_code, 'message' => $message );
	}
}
