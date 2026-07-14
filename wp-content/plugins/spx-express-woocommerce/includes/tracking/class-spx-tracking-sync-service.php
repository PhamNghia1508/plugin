<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Tracking_Sync_Service {
	/** @var SPX_Tracking_Service */ private $api;
	/** @var SPX_Tracking_Updater */ private $updater;
	public function __construct( SPX_Tracking_Service $api = null, SPX_Tracking_Updater $updater = null ) {
		$this->api = $api ?: new SPX_Tracking_Service();
		$this->updater = $updater ?: new SPX_Tracking_Updater();
	}

	public function sync_orders( array $orders, string $source = 'scheduler', int $retry_count = 0 ): array {
		$environment = SPX_Environment_Router::single_batch_environment( $orders );
		if ( $orders && '' === $environment ) { return array( 'queried' => 0, 'updated' => 0, 'retry' => array(), 'fatal' => array( 'error_code' => 'mixed_or_missing_environment', 'ret_code' => 0 ) ); }
		if ( 'production' === $environment ) { return array( 'queried' => 0, 'updated' => 0, 'retry' => array(), 'fatal' => array( 'error_code' => 'production_not_enabled', 'ret_code' => 0 ) ); }
		$map = array();
		foreach ( $orders as $order ) { if ( SPX_Tracking_Order_Query::is_eligible( $order ) ) { $map[ (string) $order->get_meta( '_spx_tracking_number', true ) ] = $order; } }
		$results = $this->api->get_many_by_tracking_numbers( array_keys( $map ) );
		$summary = array( 'queried' => count( $map ), 'updated' => 0, 'retry' => array(), 'fatal' => $results['_fatal'] ?? null );
		if ( $summary['fatal'] ) { return $summary; }
		foreach ( $map as $tracking => $order ) {
			$result = $results[ $tracking ] ?? array( 'success' => true, 'found' => false, 'ret_code' => SPX_Tracking_Service::NOT_FOUND, 'retryable' => true );
			if ( ! empty( $result['found'] ) ) { $applied = $this->updater->apply( $order, $result, $source ); $summary['updated'] += empty( $applied['updated'] ) ? 0 : 1; }
			elseif ( ! empty( $result['retryable'] ) && $retry_count < 3 ) { $summary['retry'][] = $order->get_id(); }
		}
		return $summary;
	}
}
