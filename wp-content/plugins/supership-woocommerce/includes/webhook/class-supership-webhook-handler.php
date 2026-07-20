<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Webhook Handler
 *
 * Receives and processes SuperShip webhook callbacks, and manages
 * registering our callback URL with SuperShip.
 *
 * Webhook types (docs/integrations/supership-api-reference.md section 6,
 * re-verified live at docs.developers.supership.vn/guide/webhooks.html):
 * - update_status: Order status changed
 * - update_weight: Order weight updated
 *
 * Payload fields: type, code, shortcode, soc, phone, address, amount,
 * weight, fshipment, status, status_name, partial, barter, reason_code,
 * reason_text, created_at, updated_at, pushed_at.
 *
 * Response: HTTP 200 = success (SuperShip stops retrying).
 *
 * IMPORTANT: SuperShip's webhook has no signature/secret verification of its
 * own (confirmed directly against the live docs - the only stated contract
 * is "we POST, you return HTTP 200"). Since anyone who discovers the URL
 * could otherwise POST fabricated status updates, this plugin adds its own
 * protection: a random secret is embedded in the callback URL path itself
 * and validated on every request. This is a mitigation this plugin adds,
 * not something SuperShip provides or requires.
 *
 * @package SuperShip_WooCommerce
 * @version 0.2.0
 */
final class SuperShip_Webhook_Handler {

	const REST_NAMESPACE = 'supership/v1';
	const REST_ROUTE     = '/webhook/(?P<secret>[a-f0-9]{32})';
	const SECRET_OPTION  = 'supership_webhook_secret';
	const LOG_SOURCE     = 'supership-webhook';

	/**
	 * Register the REST endpoint. Call once on boot.
	 */
	public function register_endpoint(): void {
		self::maybe_generate_secret();
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Register REST API route.
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_secret' ),
				'args'                => array(
					'secret' => array(
						'validate_callback' => static function ( $param ) {
							return is_string( $param ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Ensure a webhook secret exists. Idempotent - safe to call repeatedly.
	 *
	 * @return string The current secret.
	 */
	public static function maybe_generate_secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( '' === $secret || 1 !== preg_match( '/^[a-f0-9]{32}$/', $secret ) ) {
			$secret = bin2hex( random_bytes( 16 ) );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Rotate the webhook secret. The old URL stops working immediately -
	 * SuperShip must be re-registered with the new URL afterwards.
	 *
	 * @return string The new secret.
	 */
	public static function rotate_secret(): string {
		$secret = bin2hex( random_bytes( 16 ) );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/**
	 * REST permission callback - validates the URL-embedded secret using a
	 * constant-time comparison.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function verify_secret( WP_REST_Request $request ): bool {
		$expected = get_option( self::SECRET_OPTION, '' );
		$provided = (string) $request->get_param( 'secret' );

		if ( '' === $expected || '' === $provided ) {
			return false;
		}

		return hash_equals( $expected, $provided );
	}

	/**
	 * Get our webhook URL (the one to register with SuperShip).
	 *
	 * @return string Webhook URL, including the secret path segment.
	 */
	public static function get_webhook_url(): string {
		$secret = self::maybe_generate_secret();
		return rest_url( self::REST_NAMESPACE . '/webhook/' . $secret );
	}

	/**
	 * Register (or update) our webhook URL with SuperShip.
	 *
	 * Per docs: SuperShip stores one URL per account - calling this again
	 * with a new value overwrites the previous registration. Only orders
	 * created after a successful change apply the new URL.
	 *
	 * Endpoint: POST /v1/partner/webhooks/create
	 *
	 * @return array {success: bool, url?: string, error?: string}
	 */
	public static function register_with_supership(): array {
		if ( ! class_exists( 'SuperShip_HTTP_Client' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip HTTP client is not available', 'supership-woocommerce' ),
			);
		}

		$client = new SuperShip_HTTP_Client();
		$url    = self::get_webhook_url();

		$response = $client->post( '/v1/partner/webhooks/create', array( 'url' => $url ) );

		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}

		$results = $response->get_results();

		return array(
			'success' => true,
			'url'     => ( is_array( $results ) && isset( $results['url'] ) ) ? $results['url'] : $url,
		);
	}

	/**
	 * Get the webhook URL currently registered with SuperShip (for drift
	 * detection - e.g. after a domain change or secret rotation).
	 *
	 * Endpoint: GET /v1/partner/webhooks
	 *
	 * @return array {success: bool, url?: string, error?: string}
	 */
	public static function get_registered_with_supership(): array {
		if ( ! class_exists( 'SuperShip_HTTP_Client' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'SuperShip HTTP client is not available', 'supership-woocommerce' ),
			);
		}

		$client   = new SuperShip_HTTP_Client();
		$response = $client->get( '/v1/partner/webhooks' );

		if ( ! $response->is_success() ) {
			return array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);
		}

		$results = $response->get_results();

		return array(
			'success' => true,
			'url'     => ( is_array( $results ) && isset( $results['url'] ) ) ? $results['url'] : '',
		);
	}

	/**
	 * Handle webhook request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		if ( 'yes' !== get_option( 'supership_webhook_enabled', 'yes' ) ) {
			self::log( 'info', 'Webhook received but processing is disabled in settings - acknowledging without action' );
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Webhook processing is disabled', 'supership-woocommerce' ),
				)
			);
		}

		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			self::log( 'warning', 'Rejected webhook: empty/invalid JSON payload' );
			return new WP_Error(
				'invalid_payload',
				__( 'Invalid webhook payload', 'supership-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$validation = $this->validate_payload( $payload );
		if ( is_wp_error( $validation ) ) {
			self::log( 'warning', 'Rejected webhook: failed validation', $payload );
			return $validation;
		}

		$type = isset( $payload['type'] ) ? $payload['type'] : '';

		switch ( $type ) {
			case 'update_status':
				$result = $this->process_status_update( $payload );
				break;

			case 'update_weight':
				$result = $this->process_weight_update( $payload );
				break;

			default:
				self::log( 'warning', sprintf( 'Unknown webhook type: %s', $type ), $payload );
				return new WP_Error(
					'unknown_type',
					sprintf( __( 'Unknown webhook type: %s', 'supership-woocommerce' ), $type ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Webhook processed successfully', 'supership-woocommerce' ),
			)
		);
	}

	/**
	 * Validate webhook payload.
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private function validate_payload( array $payload ) {
		$required = array( 'type', 'code' );

		foreach ( $required as $field ) {
			if ( ! isset( $payload[ $field ] ) || '' === $payload[ $field ] ) {
				return new WP_Error(
					'missing_field',
					sprintf( __( 'Missing required field: %s', 'supership-woocommerce' ), $field ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Process status update webhook.
	 *
	 * Always refreshes the stored SuperShip status/status_name (so the
	 * admin view and timeline reflect the latest known state), but only
	 * transitions the WooCommerce order workflow status for the
	 * "significant" transitions defined in SuperShip_Status_Mapper.
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private function process_status_update( array $payload ) {
		$tracking_number = isset( $payload['code'] ) ? sanitize_text_field( $payload['code'] ) : '';
		$status          = isset( $payload['status'] ) ? (int) $payload['status'] : null;
		$status_name     = isset( $payload['status_name'] ) ? sanitize_text_field( $payload['status_name'] ) : '';
		$pushed_at       = isset( $payload['pushed_at'] ) ? sanitize_text_field( $payload['pushed_at'] ) : '';

		if ( null === $status ) {
			self::log( 'warning', 'update_status webhook missing status field', $payload );
			return new WP_Error( 'missing_status', __( 'Status field is missing', 'supership-woocommerce' ) );
		}

		$order = $this->find_order_by_tracking( $tracking_number );

		if ( ! $order ) {
			self::log( 'info', sprintf( 'Webhook for unknown tracking number: %s', $tracking_number ) );
			return true; // Not our order - not an error.
		}

		if ( $this->is_duplicate_delivery( $order, '_supership_last_status_pushed_at', $pushed_at ) ) {
			self::log(
				'debug',
				sprintf( 'Ignored duplicate status webhook for order #%d (pushed_at=%s)', $order->get_id(), $pushed_at )
			);
			return true;
		}

		$order->update_meta_data( '_supership_status', $status );
		$order->update_meta_data( '_supership_status_name', $status_name );
		if ( '' !== $pushed_at ) {
			$order->update_meta_data( '_supership_last_status_pushed_at', $pushed_at );
		}
		$order->save();

		$this->save_tracking_event( $order->get_id(), $payload );

		if ( class_exists( 'SuperShip_Status_Mapper' ) && SuperShip_Status_Mapper::should_update_wc_status( $status ) ) {
			$order->update_status(
				SuperShip_Status_Mapper::to_wc_status( $status ),
				sprintf( __( 'SuperShip status update: %s', 'supership-woocommerce' ), $status_name )
			);

			$this->add_order_note( $order, $payload );

			if ( SuperShip_Status_Mapper::should_notify_customer( $status ) ) {
				$this->send_customer_notification( $order, $status, $status_name );
			}
		}

		self::log(
			'info',
			sprintf( 'Order #%d: SuperShip status -> %s (%d)', $order->get_id(), $status_name, $status )
		);

		return true;
	}

	/**
	 * Process weight update webhook.
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private function process_weight_update( array $payload ) {
		$tracking_number = isset( $payload['code'] ) ? sanitize_text_field( $payload['code'] ) : '';
		$weight          = isset( $payload['weight'] ) ? (int) $payload['weight'] : 0;
		$pushed_at       = isset( $payload['pushed_at'] ) ? sanitize_text_field( $payload['pushed_at'] ) : '';

		$order = $this->find_order_by_tracking( $tracking_number );

		if ( ! $order ) {
			self::log( 'info', sprintf( 'Weight webhook for unknown tracking number: %s', $tracking_number ) );
			return true;
		}

		if ( $this->is_duplicate_delivery( $order, '_supership_last_weight_pushed_at', $pushed_at ) ) {
			self::log(
				'debug',
				sprintf( 'Ignored duplicate weight webhook for order #%d (pushed_at=%s)', $order->get_id(), $pushed_at )
			);
			return true;
		}

		$order->update_meta_data( '_supership_actual_weight', $weight );
		if ( '' !== $pushed_at ) {
			$order->update_meta_data( '_supership_last_weight_pushed_at', $pushed_at );
		}
		$order->save();

		$order->add_order_note(
			sprintf( __( 'SuperShip weight updated: %d grams', 'supership-woocommerce' ), $weight )
		);

		self::log( 'info', sprintf( 'Order #%d: SuperShip weight -> %dg', $order->get_id(), $weight ) );

		return true;
	}

	/**
	 * Check whether this webhook delivery has already been processed, using
	 * the `pushed_at` ISO 8601 timestamp SuperShip includes on every push.
	 * Guards against duplicate order notes/notifications if SuperShip
	 * retries a delivery.
	 *
	 * @param WC_Order $order Order to check.
	 * @param string   $meta_key Meta key holding the last-seen pushed_at value.
	 * @param string   $pushed_at Incoming pushed_at value.
	 * @return bool True if this delivery should be skipped as a duplicate.
	 */
	private function is_duplicate_delivery( WC_Order $order, string $meta_key, string $pushed_at ): bool {
		if ( '' === $pushed_at ) {
			return false; // No timestamp to compare - process it.
		}

		$last = $order->get_meta( $meta_key );

		if ( '' === $last ) {
			return false;
		}

		$last_time    = strtotime( $last );
		$current_time = strtotime( $pushed_at );

		if ( false === $last_time || false === $current_time ) {
			return false;
		}

		return $current_time <= $last_time;
	}

	/**
	 * Find WooCommerce order by SuperShip tracking number.
	 *
	 * @param string $tracking_number SuperShip tracking number.
	 * @return WC_Order|null
	 */
	private function find_order_by_tracking( string $tracking_number ): ?WC_Order {
		if ( '' === $tracking_number ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_supership_tracking_number',
				'meta_value' => $tracking_number,
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Save tracking event to database (transient-backed timeline used by
	 * the admin order metabox and customer tracking view).
	 *
	 * @param int   $order_id Order ID.
	 * @param array $payload Webhook payload.
	 */
	private function save_tracking_event( int $order_id, array $payload ): void {
		$events_key = 'supership_events_' . $order_id;
		$events     = get_transient( $events_key ) ?: array();

		$events[] = array(
			'timestamp'   => current_time( 'mysql' ),
			'status'      => isset( $payload['status'] ) ? (int) $payload['status'] : 0,
			'status_name' => isset( $payload['status_name'] ) ? $payload['status_name'] : '',
			'reason_code' => isset( $payload['reason_code'] ) ? $payload['reason_code'] : '',
			'reason_text' => isset( $payload['reason_text'] ) ? $payload['reason_text'] : '',
		);

		// Keep last 50 events.
		$events = array_slice( $events, -50 );

		set_transient( $events_key, $events, MONTH_IN_SECONDS );
	}

	/**
	 * Add detailed order note.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $payload Webhook payload.
	 */
	private function add_order_note( WC_Order $order, array $payload ): void {
		$note_parts = array();

		if ( isset( $payload['status_name'] ) ) {
			$note_parts[] = sprintf( __( 'Status: %s', 'supership-woocommerce' ), $payload['status_name'] );
		}

		if ( isset( $payload['reason_text'] ) && '' !== $payload['reason_text'] ) {
			$note_parts[] = sprintf( __( 'Reason: %s', 'supership-woocommerce' ), $payload['reason_text'] );
		}

		if ( ! empty( $note_parts ) ) {
			$order->add_order_note( implode( ' | ', $note_parts ) );
		}
	}

	/**
	 * Send customer notification.
	 *
	 * @param WC_Order $order Order object.
	 * @param int      $status SuperShip status code.
	 * @param string   $status_name SuperShip status name.
	 */
	private function send_customer_notification( WC_Order $order, int $status, string $status_name ): void {
		$message = SuperShip_Status_Mapper::get_customer_message(
			$status,
			$order->get_meta( '_supership_tracking_number' )
		);

		// Customer-visible order note (shown in "order notes" email digest / My Account if enabled).
		$order->add_order_note( $message, true );
	}

	/**
	 * Log via WC_Logger (visible under WooCommerce > Status > Logs
	 * regardless of WP_DEBUG, unlike error_log()).
	 *
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 * @param array  $context Extra context (e.g. the payload).
	 */
	private static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array_merge( array( 'source' => self::LOG_SOURCE ), $context ) );
	}
}
