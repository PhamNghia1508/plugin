<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Tracking Scheduler
 *
 * Polls SuperShip for tracking updates via WP-Cron, as a fallback/complement
 * to the webhook push (class-supership-webhook-handler.php). Useful when the
 * shop is not publicly reachable for SuperShip's webhook callback, or as a
 * safety net if a webhook delivery is missed.
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Tracking_Scheduler {

	const CRON_HOOK      = 'supership_tracking_sync';
	const CRON_SCHEDULE  = 'supership_tracking_interval';
	const BATCH_LIMIT    = 20;

	/**
	 * Register cron hooks. Safe to call on every request (plugins_loaded).
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync' ) );

		if ( 'yes' !== get_option( 'supership_auto_tracking_enabled', 'yes' ) ) {
			self::deactivate();
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Add a custom cron interval based on the configured minutes.
	 *
	 * @param array $schedules Existing WP-Cron schedules.
	 * @return array
	 */
	public static function register_interval( array $schedules ): array {
		$minutes = (int) get_option( 'supership_auto_tracking_interval', 60 );
		$minutes = max( 15, min( 1440, $minutes ) );

		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: interval in minutes */
				__( 'Mỗi %d phút (theo dõi vận đơn SuperShip)', 'supership-woocommerce' ),
				$minutes
			),
		);

		return $schedules;
	}

	/**
	 * Clear the scheduled event (plugin deactivation).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Poll SuperShip for orders that have a tracking number but are not yet
	 * in a final status, and sync their WooCommerce status.
	 */
	public static function sync(): void {
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'SuperShip_Tracking_Service' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH_LIMIT,
				'meta_query' => array(
					array(
						'key'     => '_supership_tracking_number',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		self::log( 'debug', sprintf( 'Tracking sync starting for %d order(s)', count( $orders ) ) );

		$tracking_service = new SuperShip_Tracking_Service();

		foreach ( $orders as $order ) {
			self::sync_order( $order, $tracking_service );
		}
	}

	/**
	 * Sync a single order's tracking status.
	 *
	 * @param WC_Order                $order Order to sync.
	 * @param SuperShip_Tracking_Service $tracking_service Tracking service instance.
	 */
	private static function sync_order( WC_Order $order, SuperShip_Tracking_Service $tracking_service ): void {
		$tracking_number = $order->get_meta( '_supership_tracking_number' );

		if ( '' === $tracking_number ) {
			return;
		}

		$current_status = (int) $order->get_meta( '_supership_status' );

		if ( class_exists( 'SuperShip_Status_Mapper' ) && SuperShip_Status_Mapper::is_final_status( $current_status ) ) {
			return; // Already settled, stop polling this order.
		}

		$result = $tracking_service->get_tracking( $tracking_number );

		if ( ! $result['success'] ) {
			self::log(
				'warning',
				sprintf( 'Order #%d: tracking sync failed - %s', $order->get_id(), $result['error'] ?? 'unknown error' )
			);
			return;
		}

		$tracking   = $result['tracking'];
		$new_status = (int) $tracking['status'];

		if ( isset( $tracking['journeys'] ) && is_array( $tracking['journeys'] ) ) {
			$order->update_meta_data( '_supership_journeys', $tracking['journeys'] );
			$order->save();
		}

		if ( $new_status === $current_status ) {
			return; // No status change - journeys (if any) were already saved above.
		}

		$order->update_meta_data( '_supership_status', $new_status );
		$order->update_meta_data( '_supership_status_name', $tracking['status_name'] );
		$order->save();

		if ( class_exists( 'SuperShip_Status_Mapper' ) && SuperShip_Status_Mapper::should_update_wc_status( $new_status ) ) {
			$order->update_status(
				SuperShip_Status_Mapper::to_wc_status( $new_status ),
				sprintf(
					/* translators: %s: SuperShip status name */
					__( 'SuperShip tracking sync: %s', 'supership-woocommerce' ),
					$tracking['status_name']
				)
			);
		}

		self::log(
			'info',
			sprintf( 'Order #%d: SuperShip status -> %s (%d) via cron sync', $order->get_id(), $tracking['status_name'], $new_status )
		);
	}

	/**
	 * Log via WC_Logger (visible under WooCommerce > Status > Logs
	 * regardless of WP_DEBUG).
	 *
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 */
	private static function log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => 'supership-tracking-scheduler' ) );
	}
}
