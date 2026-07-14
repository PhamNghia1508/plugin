<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SPX_Order_Environment' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-order-environment.php'; }

/**
 * Admin-only manual SPX shipment creation + status sync for a single order,
 * via the core WooCommerce order-action mechanism (capability + nonce, POST,
 * never automatic). Persists tracking via WC_Order CRUD, handles duplicate /
 * unknown states safely, and never changes the order total or WooCommerce status.
 */
final class SPX_Admin_Shipment {
	const CAP    = 'edit_shop_orders';
	const CREATE = 'spx_create_shipment_api';
	const SYNC   = 'spx_sync_tracking';

	const M_TRACKING   = '_spx_tracking_number';
	const M_CLIENT_ID  = '_spx_client_order_id';
	const M_LINK       = '_spx_tracking_link';
	const M_STATUS     = '_spx_shipment_status';
	const M_STATUS_CODE= '_spx_shipment_status_code';
	const M_STATUS_VI  = '_spx_shipment_status_label';
	const M_CREATED    = '_spx_shipment_created_at';
	const M_ENV        = '_spx_shipment_environment';
	const M_STATE      = '_spx_create_state';
	const M_SYNCED     = '_spx_last_sync_at';
	const M_SOURCE     = '_spx_create_source';
	const M_ATTEMPTED  = '_spx_create_attempted_at';
	const M_FEES       = '_spx_create_fees';
	const LOCK_PREFIX  = 'spx_create_lock_';

	public static function init() {
		add_action( 'woocommerce_order_action_' . self::CREATE, array( __CLASS__, 'run_create' ) );
		add_action( 'woocommerce_order_action_' . self::SYNC, array( __CLASS__, 'run_sync' ) );
	}

	public static function add_actions( array $actions ): array {
		if ( ! current_user_can( self::CAP ) ) { return $actions; }
		$config = SPX_API_Config::for_test();
		if ( SPX_API_Config::TEST_ENV !== $config->get_environment() || ! $config->has_account_credentials() ) { return $actions; }
		$actions[ self::CREATE ] = __( 'Tạo vận đơn SPX (Sandbox)', 'spx-express-woocommerce' );
		$actions[ self::SYNC ]   = __( 'Đồng bộ trạng thái SPX', 'spx-express-woocommerce' );
		return $actions;
	}

	/* ---- Create --------------------------------------------------------- */

	public static function run_create( $order ) {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) { return; }

		$existing = (string) $order->get_meta( self::M_TRACKING, true );
		$guard    = self::create_guard_reason( $order );
		if ( '' !== $guard ) {
			$msg = 0 === stripos( $existing, 'SPXMOCK' )
				? __( 'Đơn này đã có mã mock (SPXMOCK). Hãy dùng một order test mới để tạo vận đơn sandbox.', 'spx-express-woocommerce' )
				: ( '' !== $existing
					? __( 'Đơn này đã có mã vận đơn SPX; không tạo lại.', 'spx-express-woocommerce' )
					: __( 'Đơn này đã từng gửi yêu cầu Create tới SPX. Không tạo lại; chỉ dùng Đồng bộ trạng thái SPX.', 'spx-express-woocommerce' ) );
			$order->add_order_note( $msg );
			return; // no API call
		}

		$shipment = self::build_shipment( $order );
		$ready    = SPX_Shipment_Readiness::evaluate( self::readiness_ctx( $shipment, $order ) );
		if ( ! $ready['ready'] ) {
			$fields = implode( ', ', array_map( function ( $e ) { return $e['field']; }, $ready['errors'] ) );
			$order->add_order_note( sprintf( __( 'Chưa đủ điều kiện tạo vận đơn SPX: %s', 'spx-express-woocommerce' ), $fields ) );
			return;
		}

		if ( ! self::acquire_create_lock( $order, $shipment['client_order_id'] ) ) {
			$order->add_order_note( __( 'Yêu cầu Create SPX đã được khóa cho order này; không gửi thêm yêu cầu.', 'spx-express-woocommerce' ) );
			return;
		}
		self::prepare_create_attempt( $order, $shipment['client_order_id'] );
		$result = ( new SPX_Api_Provider() )->create_shipment( $shipment );
		self::apply_create_result( $order, $shipment['client_order_id'], $result );
		if ( 'created' === (string) $order->get_meta( self::M_STATE, true ) && SPX_Shipment_Service::is_real_tracking( (string) $order->get_meta( self::M_TRACKING, true ) ) ) {
			self::persist_parcel_snapshot( $order, $shipment );
		}
	}

	/**
	 * Persist a PII-free parcel snapshot for audit/reconciliation the first time a
	 * shipment is created. Never rewrites an existing snapshot and never changes the
	 * order total or shipping total. Uses WC_Order CRUD only.
	 */
	public static function persist_parcel_snapshot( WC_Order $order, array $shipment ): void {
		if ( '' !== (string) $order->get_meta( '_spx_parcel_weight_kg', true ) ) { return; }
		$snapshot = isset( $shipment['parcel'] ) && is_array( $shipment['parcel'] ) ? $shipment['parcel'] : array();
		if ( empty( $snapshot['_spx_parcel_weight_kg'] ) ) { return; }
		foreach ( $snapshot as $key => $value ) {
			if ( is_scalar( $value ) ) { $order->update_meta_data( sanitize_key( (string) $key ), sanitize_text_field( (string) $value ) ); }
		}
		$order->update_meta_data( '_spx_parcel_policy', sanitize_key( (string) ( $shipment['parcel_policy'] ?? '' ) ) );
		$order->update_meta_data( '_spx_parcel_created_at', gmdate( 'c' ) );
		$order->save();
	}

	/** Return an empty string only when no create attempt has ever been made. */
	public static function create_guard_reason( WC_Order $order ): string {
		if ( '' !== (string) $order->get_meta( self::M_TRACKING, true ) ) { return 'tracking_exists'; }
		if ( '' !== (string) $order->get_meta( self::M_ATTEMPTED, true ) ) { return 'already_attempted'; }
		if ( '' !== (string) $order->get_meta( self::M_CLIENT_ID, true ) ) { return 'already_attempted'; }
		$state = (string) $order->get_meta( self::M_STATE, true );
		return in_array( $state, array( 'attempting', 'unknown', 'duplicate_unresolved', 'created', 'failed' ), true ) ? 'already_attempted' : '';
	}

	/** Persist the idempotency lock before the single side-effecting HTTP call. */
	public static function prepare_create_attempt( WC_Order $order, string $client_id ) {
		$order->update_meta_data( self::M_CLIENT_ID, sanitize_text_field( $client_id ) );
		$order->update_meta_data( self::M_ATTEMPTED, gmdate( 'c' ) );
		$order->update_meta_data( self::M_ENV, 'test' );
		SPX_Order_Environment::bind( $order, 'test' );
		$order->update_meta_data( self::M_STATE, 'attempting' );
		$order->save();
	}

	/** add_option is atomic and closes the double-click/concurrent-request race. */
	public static function acquire_create_lock( WC_Order $order, string $client_id ): bool {
		if ( ! function_exists( 'add_option' ) ) { return true; }
		return add_option( self::LOCK_PREFIX . $order->get_id(), sanitize_text_field( $client_id ), '', false );
	}

	/** Persist an already-fetched Create result; the optional service keeps duplicate recovery auditable. */
	public static function apply_create_result( WC_Order $order, string $client_id, array $result, $tracking_service = null ) {
		$state = $result['state'] ?? 'failed';

		if ( true === ( $result['success'] ?? false ) && 'created' === $state ) {
			if ( ! SPX_Shipment_Service::is_real_tracking( (string) ( $result['tracking_no'] ?? '' ) ) ) {
				$result = array( 'success' => false, 'state' => 'unknown' );
				$state  = 'unknown';
			} else {
			self::save_tracking( $order, $result['tracking_no'], $result['tracking_link'] ?? '', '1001', 'created', $result['created_at'] ?? gmdate( 'c' ), 'api' );
			$order->update_meta_data( self::M_CLIENT_ID, sanitize_text_field( $client_id ) );
			$order->update_meta_data( self::M_FEES, self::safe_fees( $result['fees'] ?? array() ) );
			$order->add_order_note( sprintf( __( 'Đã tạo vận đơn SPX (sandbox). Mã vận đơn: %s', 'spx-express-woocommerce' ), sanitize_text_field( $result['tracking_no'] ) ) );
			$order->save();
			SPX_Logger::log( 'info', 'shipment_created_api', $order->get_id(), sanitize_text_field( $result['tracking_no'] ), 'env=test' );
			return;
			}
		}

		if ( 'duplicate' === $state ) {
			$service = $tracking_service ?: new SPX_Tracking_Service();
			$rec = $service->get_by_client_order_id( $client_id );
			if ( ! empty( $rec['found'] ) && SPX_Shipment_Service::is_real_tracking( (string) $rec['tracking_no'] ) ) {
				self::save_tracking( $order, $rec['tracking_no'], $rec['tracking_link'] ?? '', $rec['status_code'] ?? '', $rec['internal_status'] ?? 'created', gmdate( 'c' ), 'recovered_duplicate' );
				$order->update_meta_data( self::M_CLIENT_ID, sanitize_text_field( $client_id ) );
				$order->update_meta_data( self::M_STATUS_VI, sanitize_text_field( $rec['customer_label'] ?? '' ) );
				$order->add_order_note( sprintf( __( 'SPX báo trùng; đã phục hồi mã vận đơn: %s', 'spx-express-woocommerce' ), sanitize_text_field( $rec['tracking_no'] ) ) );
			} else {
				$order->update_meta_data( self::M_STATE, 'duplicate_unresolved' );
				$order->update_meta_data( self::M_CLIENT_ID, sanitize_text_field( $client_id ) );
				$order->add_order_note( __( 'SPX báo trùng nhưng chưa tra cứu được mã vận đơn. Không tạo lại; hãy dùng Đồng bộ trạng thái sau.', 'spx-express-woocommerce' ) );
			}
			$order->save();
			return;
		}

		if ( 'unknown' === $state ) {
			$order->update_meta_data( self::M_STATE, 'unknown' );
			$order->update_meta_data( self::M_CLIENT_ID, sanitize_text_field( $client_id ) );
			$order->update_meta_data( self::M_ATTEMPTED, gmdate( 'c' ) );
			$order->add_order_note( __( 'Không xác định được SPX đã tạo vận đơn hay chưa. KHÔNG tạo lại; hãy dùng Đồng bộ trạng thái để tra cứu.', 'spx-express-woocommerce' ) );
			$order->save();
			SPX_Logger::log( 'warning', 'shipment_create_unknown', $order->get_id(), '', 'client=' . $client_id );
			return;
		}

		// failed
		$order->update_meta_data( self::M_STATE, 'failed' );
		$order->add_order_note( sprintf( __( 'Tạo vận đơn SPX thất bại: %s', 'spx-express-woocommerce' ), sanitize_text_field( $result['message'] ?? '' ) ) );
		$order->save();
		SPX_Logger::log( 'error', 'shipment_create_failed', $order->get_id(), '', 'code=' . ( $result['error_code'] ?? '' ) . ' ret=' . ( $result['ret_code'] ?? '' ) );
	}

	private static function save_tracking( WC_Order $order, string $tracking, string $link, string $code, string $status, string $created, string $source ) {
		$order->update_meta_data( self::M_TRACKING, sanitize_text_field( $tracking ) );
		if ( '' !== $link && 'https' === strtolower( (string) wp_parse_url( $link, PHP_URL_SCHEME ) ) ) { $order->update_meta_data( self::M_LINK, esc_url_raw( $link ) ); }
		$order->update_meta_data( self::M_STATUS, sanitize_key( $status ) );
		$order->update_meta_data( self::M_STATUS_CODE, sanitize_text_field( $code ) );
		$mapped = SPX_Tracking_Status_Mapper::map( $code );
		$order->update_meta_data( self::M_STATUS_VI, sanitize_text_field( $mapped['customer_label'] ) );
		$order->update_meta_data( self::M_CREATED, sanitize_text_field( $created ) );
		$order->update_meta_data( self::M_ENV, 'test' );
		SPX_Order_Environment::bind( $order, 'test' );
		$order->update_meta_data( self::M_STATE, 'created' );
		$order->update_meta_data( self::M_SYNCED, gmdate( 'c' ) );
		$order->update_meta_data( self::M_SOURCE, sanitize_key( $source ) );
	}

	/** Persist only documented fee fields, raw and unscaled. */
	private static function safe_fees( array $fees ): array {
		$out = array( 'fee_unit' => 'unconfirmed', 'currency' => '' );
		foreach ( array( 'estimated_shipping_fee', 'basic_shipping_fee', 'cod_service_fee', 'high_value_processing_fee', 'vat_fee', 'voucher_shipping_fee' ) as $key ) {
			if ( isset( $fees[ $key ] ) && is_numeric( $fees[ $key ] ) ) { $out[ $key ] = 0 + $fees[ $key ]; }
		}
		return $out;
	}

	/* ---- Sync ----------------------------------------------------------- */

	public static function run_sync( $order ) {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) { return; }

		$tracking = (string) $order->get_meta( self::M_TRACKING, true );
		$client   = (string) $order->get_meta( self::M_CLIENT_ID, true );
		$environment = SPX_Order_Environment::get( $order );
		$svc = SPX_Environment_Router::tracking_service( $environment );
		if ( ! $svc ) { $order->add_order_note( __( 'SPX environment is missing or production is not enabled.', 'spx-express-woocommerce' ) ); return; }

		if ( '' !== $tracking && SPX_Shipment_Service::is_real_tracking( $tracking ) ) {
			$r = $svc->get_by_tracking_number( $tracking );
		} elseif ( '' !== $client ) {
			$r = $svc->get_by_client_order_id( $client );
		} else {
			$order->add_order_note( __( 'Chưa có mã vận đơn SPX để đồng bộ.', 'spx-express-woocommerce' ) );
			return;
		}

		if ( empty( $r['success'] ) ) {
			$order->add_order_note( sprintf( __( 'Đồng bộ SPX lỗi: %s', 'spx-express-woocommerce' ), sanitize_text_field( $r['message'] ?? '' ) ) );
			return;
		}
		if ( empty( $r['found'] ) ) {
			$order->add_order_note( __( 'SPX chưa đồng bộ trạng thái, vui lòng thử lại sau.', 'spx-express-woocommerce' ) );
			return;
		}

		self::apply_tracking_result( $order, $r, $tracking );
	}

	/** Persist an already-fetched tracking result without issuing another query. */
	public static function apply_tracking_result( WC_Order $order, array $r, string $previous_tracking = '' ) {
		if ( empty( $r['success'] ) || empty( $r['found'] ) ) { return; }
		$tracking = '' !== $previous_tracking ? $previous_tracking : (string) $order->get_meta( self::M_TRACKING, true );

		// Recovered a tracking via client id (unknown/duplicate) — persist it.
		if ( ( '' === $tracking || ! SPX_Shipment_Service::is_real_tracking( $tracking ) ) && SPX_Shipment_Service::is_real_tracking( (string) $r['tracking_no'] ) ) {
			self::save_tracking( $order, $r['tracking_no'], $r['tracking_link'] ?? '', $r['status_code'] ?? '', $r['internal_status'] ?? 'created', gmdate( 'c' ), 'recovered_sync' );
		}
		( new SPX_Tracking_Updater() )->apply( $order, $r, 'manual' );
		SPX_Logger::log( 'info', 'shipment_synced', $order->get_id(), $tracking, 'status=' . ( $r['internal_status'] ?? '' ) );
	}

	/* ---- Admin panel ---------------------------------------------------- */

	public static function render_panel( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( self::CAP ) ) { return; }
		$state = (string) $order->get_meta( self::M_STATE, true );
		$track = (string) $order->get_meta( self::M_TRACKING, true );
		if ( '' === $state && '' === $track ) { return; }
		echo '<div class="spx-shipment-api"><h3>' . esc_html__( 'Vận đơn SPX', 'spx-express-woocommerce' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Environment', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html__( 'Sandbox/Test', 'spx-express-woocommerce' ) . '</p>';
		if ( 'unknown' === $state ) {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html__( 'Không xác định được SPX đã tạo vận đơn hay chưa. Không tạo lại; hãy dùng Đồng bộ trạng thái để tra cứu.', 'spx-express-woocommerce' ) . '</strong></p>';
		}
		self::row( __( 'Client order ID', 'spx-express-woocommerce' ), $order->get_meta( self::M_CLIENT_ID, true ) );
		self::row( __( 'Mã vận đơn', 'spx-express-woocommerce' ), $track && ! self::is_mock( $track ) ? $track : '' );
		$link = (string) $order->get_meta( self::M_LINK, true );
		if ( '' !== $link ) { echo '<p><strong>' . esc_html__( 'Tracking link', 'spx-express-woocommerce' ) . ':</strong> <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link ) . '</a></p>'; }
		$code = (string) $order->get_meta( self::M_STATUS_CODE, true );
		if ( '' !== $code ) {
			$m = SPX_Tracking_Status_Mapper::map( $code );
			self::row( __( 'Trạng thái SPX', 'spx-express-woocommerce' ), $m['official_status'] );
			self::row( __( 'Trạng thái', 'spx-express-woocommerce' ), $m['customer_label'] );
		}
		self::row( __( 'Ngày tạo', 'spx-express-woocommerce' ), $order->get_meta( self::M_CREATED, true ) );
		self::row( __( 'Đồng bộ cuối', 'spx-express-woocommerce' ), $order->get_meta( self::M_SYNCED, true ) );
		self::row( __( 'Create state', 'spx-express-woocommerce' ), $state ?: 'not_created' );
		$fees = $order->get_meta( self::M_FEES, true );
		if ( is_array( $fees ) && count( $fees ) > 2 ) {
			echo '<p><strong>' . esc_html__( 'SPX raw fee — đơn vị chưa được SPX xác nhận', 'spx-express-woocommerce' ) . '</strong></p>';
			foreach ( $fees as $key => $value ) {
				if ( in_array( $key, array( 'fee_unit', 'currency' ), true ) ) { continue; }
				self::row( $key, $value );
			}
		}
		echo '</div>';
	}

	private static function row( string $label, $value ) {
		if ( '' === (string) $value ) { return; }
		echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</p>';
	}

	private static function is_mock( string $t ): bool { return 0 === stripos( $t, 'SPXMOCK' ); }

	/* ---- shipment building (shared shape with rate) --------------------- */

	public static function build_shipment( WC_Order $order ): array {
		$repo       = new SPX_Address_Repository();
		$profile    = SPX_Sender_Profile::get();
		$resolution = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
		$payload    = SPX_Order_Mapper::build( $order, SPX_Settings::get_instance_settings() );
		$shipment   = SPX_Order_Mapper::enrich_for_spx( $payload, $profile, $resolution );
		$ward       = ! empty( $resolution['district_code'] ) && ! empty( $resolution['ward_code'] ) ? $repo->get_ward( $resolution['district_code'], $resolution['ward_code'] ) : null;
		if ( $ward ) {
			$shipment['recipient']['delivery_supported'] = ! empty( $ward['delivery'] );
			$shipment['recipient']['cod_supported'] = ! empty( $ward['cod'] );
			$shipment['recipient']['service_status'] = (string) $ward['status'];
		}
		$shipment['client_order_id'] = SPX_Client_Order_ID::for_order( $order, SPX_API_Config::TEST_ENV );
		$shipment['service_type']    = (int) $profile['service_type'];
		$shipment['collect_type']    = (int) $profile['collect_type'];
		$shipment['payment_role']    = (int) $profile['payment_role'];
		return $shipment;
	}

	/** Expose the existing readiness calculation to presentation code. */
	public static function readiness_for_order( WC_Order $order ): array {
		return SPX_Shipment_Readiness::evaluate( self::readiness_ctx( self::build_shipment( $order ), $order ) );
	}

	private static function readiness_ctx( array $shipment, WC_Order $order ): array {
		return array(
			'sender'    => SPX_Sender_Profile::get(),
			'recipient' => array(
				'name'    => $shipment['recipient']['name'] ?? '',
				'phone'   => $shipment['recipient']['phone'] ?? '',
				'address' => $shipment['recipient']['address'] ?? '',
				'province_code' => $shipment['recipient']['province_code'] ?? '',
				'district_code' => $shipment['recipient']['district_code'] ?? '',
				'ward_code'     => $shipment['recipient']['ward_code'] ?? '',
				'delivery_supported' => $shipment['recipient']['delivery_supported'] ?? null,
				'cod_supported' => $shipment['recipient']['cod_supported'] ?? null,
				'service_status' => $shipment['recipient']['service_status'] ?? '',
			),
			'parcel'    => array(
				'weight_grams'  => $shipment['weight_grams'] ?? 0,
				'item_quantity' => max( 1, count( isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : array() ) ),
				'errors'        => isset( $shipment['parcel_errors'] ) && is_array( $shipment['parcel_errors'] ) ? $shipment['parcel_errors'] : array(),
			),
			'payment'   => isset( $shipment['payment'] ) && is_array( $shipment['payment'] ) ? $shipment['payment'] : SPX_Payment_Resolver::resolve( $order ),
		);
	}
}
