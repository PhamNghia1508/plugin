<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SPX_Order_Environment' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-order-environment.php'; }

final class SPX_Tracking_Updater {
	/** @var SPX_Tracking_Event_Repository */ private $events;
	/** @var SPX_Woo_Status_Mapping_Service */ private $status_mapping;
	public function __construct( SPX_Tracking_Event_Repository $events = null, SPX_Woo_Status_Mapping_Service $status_mapping = null ) {
		$this->events = $events ?: new SPX_Tracking_Event_Repository();
		$this->status_mapping = $status_mapping ?: new SPX_Woo_Status_Mapping_Service();
	}

	public function apply( WC_Order $order, array $result, string $source = 'search' ): array {
		if ( empty( $result['success'] ) || empty( $result['found'] ) || ! SPX_Tracking_Service::is_real_tracking( (string) ( $result['tracking_no'] ?? '' ) ) ) {
			return array( 'updated' => false, 'inserted' => 0, 'error' => 'invalid_result' );
		}
		$tracking = (string) $result['tracking_no'];
		$current_ts = (int) $order->get_meta( '_spx_last_status_event_at', true );
		if ( ! $current_ts ) { $current_ts = (int) $order->get_meta( '_spx_tracking_event_timestamp', true ); }
		$current_status = (string) $order->get_meta( '_spx_shipment_status', true );
		$current_code = (string) $order->get_meta( '_spx_shipment_status_code', true );
		$events = SPX_Tracking_Route_Parser::parse( (array) ( $result['routes'] ?? array() ), $tracking, $source );
		if ( empty( $events ) ) {
			$events[] = array(
				'tracking_no' => $tracking, 'event_id' => '',
				'status_code' => (string) ( $result['status_code'] ?? '' ), 'official_status' => (string) ( $result['official_status'] ?? '' ),
				'internal_status' => (string) ( $result['internal_status'] ?? 'unknown' ),
				'customer_label' => (string) ( $result['customer_label'] ?? '' ),
				'customer_message' => (string) ( $result['customer_label'] ?? '' ),
				'event_timestamp' => ( $current_ts && $current_status === (string) ( $result['internal_status'] ?? 'unknown' ) ) ? $current_ts : time(), 'terminal' => ! empty( $result['terminal'] ), 'source' => $source,
			);
		}
		$inserted = 0;
		foreach ( $events as $event ) {
			$event['order_id'] = $order->get_id();
			$event['is_stale'] = $current_ts && $event['event_timestamp'] < $current_ts;
			$stored = $this->events->insert( $event );
			if ( $stored['inserted'] ) { ++$inserted; }
		}
		$latest = end( $events );
		$conflict = $current_ts && $latest['event_timestamp'] === $current_ts && '' !== $current_status && $current_status !== $latest['internal_status'];
		$terminal_current = in_array( $current_status, array( 'delivered', 'damaged', 'lost', 'returned', 'cancelled' ), true );
		$may_advance = ! $conflict && ( ! $current_ts || $latest['event_timestamp'] > $current_ts ) && ! ( $terminal_current && empty( $latest['terminal'] ) );
		$changed = false;
		$status_code_changed = false;
		if ( $may_advance ) {
			$changed = $current_status !== $latest['internal_status'];
			$status_code_changed = $current_code !== (string) $latest['status_code'];
			$order->update_meta_data( '_spx_shipment_status_code', $latest['status_code'] );
			$order->update_meta_data( '_spx_shipment_status', $latest['internal_status'] );
			$order->update_meta_data( '_spx_shipment_status_label', $latest['customer_label'] );
			$order->update_meta_data( '_spx_tracking_event_timestamp', $latest['event_timestamp'] );
			$order->update_meta_data( '_spx_last_status_event_at', $latest['event_timestamp'] );
		}
		$order->update_meta_data( '_spx_tracking_number', $tracking );
		$environment = SPX_Order_Environment::get( $order );
		if ( '' !== $environment ) { SPX_Order_Environment::bind( $order, $environment ); }
		$order->update_meta_data( '_spx_last_sync_at', gmdate( 'c' ) );
		$order->update_meta_data( '_spx_tracking_source', sanitize_key( $source ) );
		$order->update_meta_data( '_spx_last_sync_source', sanitize_key( $source ) );
		if ( ! empty( $result['tracking_link'] ) && 'https' === strtolower( (string) wp_parse_url( $result['tracking_link'], PHP_URL_SCHEME ) ) ) { $order->update_meta_data( '_spx_tracking_link', esc_url_raw( $result['tracking_link'] ) ); }
		if ( ! empty( $result['edd_min'] ) ) { $order->update_meta_data( '_spx_estimated_delivery_min', sanitize_text_field( $result['edd_min'] ) ); }
		if ( ! empty( $result['edd_max'] ) ) { $order->update_meta_data( '_spx_estimated_delivery_max', sanitize_text_field( $result['edd_max'] ) ); }
		$order->update_meta_data( '_spx_tracking_event_count', $this->events->count_by_order( $order->get_id() ) );
		if ( $conflict ) { $order->update_meta_data( '_spx_tracking_conflict', gmdate( 'c' ) ); SPX_Tracking_Scheduler::schedule_canonical_verification( $order->get_id() ); }
		if ( $changed ) { $order->add_order_note( sprintf( __( 'Trạng thái SPX: %s', 'spx-express-woocommerce' ), sanitize_text_field( $latest['customer_label'] ) ) ); }
		$order->save();

		$mapping = array( 'result' => 'not_evaluated', 'applied' => false );
		if ( $status_code_changed ) {
			$mapping_source = 'scheduler_retry' === sanitize_key( $source ) ? 'scheduler' : $source;
			try {
				$mapping = $this->status_mapping->apply( $order, $current_code, (string) $latest['status_code'], $mapping_source, (int) $latest['event_timestamp'] );
			} catch ( Throwable $error ) {
				SPX_Logger::log( 'error', 'woo_status_mapping_error', $order->get_id(), '', 'code=' . sanitize_key( (string) $latest['status_code'] ) );
				$mapping = array( 'result' => 'error', 'applied' => false );
			}
		}
		return array( 'updated' => $changed, 'inserted' => $inserted, 'conflict' => $conflict, 'status' => $latest['internal_status'], 'mapping' => $mapping );
	}
}
