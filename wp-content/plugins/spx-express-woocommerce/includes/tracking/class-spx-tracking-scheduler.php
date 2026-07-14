<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Tracking_Scheduler {
	const HOOK = 'spx_tracking_sync';
	const RETRY_HOOK = 'spx_tracking_retry';
	const LOCK = 'spx_tracking_sync_lock';
	const GROUP = 'spx-tracking';
	const TEST_GROUP = 'spx-tracking-test';
	const PRODUCTION_GROUP = 'spx-tracking-production';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run_retry' ), 10, 3 );
		add_action( 'spx_tracking_cron', array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
	}

	public static function settings(): array {
		return wp_parse_args( get_option( 'spx_tracking_settings', array() ), array( 'enabled' => 'yes', 'interval' => 15, 'batch_size' => 100 ) );
	}

	public static function ensure_schedule(): void {
		$s = self::settings();
		if ( 'yes' !== $s['enabled'] ) { self::unschedule(); return; }
		$seconds = max( 300, min( 3600, (int) $s['interval'] * MINUTE_IN_SECONDS ) );
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( as_has_scheduled_action( self::HOOK, array(), self::GROUP ) && function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( self::HOOK, array(), self::GROUP ); }
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::TEST_GROUP ) ) { as_schedule_recurring_action( time() + 60, $seconds, self::HOOK, array(), self::TEST_GROUP, true ); }
		} elseif ( ! wp_next_scheduled( 'spx_tracking_cron' ) ) { wp_schedule_event( time() + 60, 'spx_tracking_' . $seconds, 'spx_tracking_cron' ); }
	}

	public static function run(): void {
		if ( ! self::acquire_lock() ) { return; }
		try {
			$page = 1; $query = new SPX_Tracking_Order_Query(); $sync = new SPX_Tracking_Sync_Service(); $health = array( 'queried' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'retry' => 0, 'last_error' => '', 'last_success' => '' );
			do {
				$result = $query->page( $page, (int) self::settings()['batch_size'] );
				$groups = array(); foreach ( $result['orders'] as $order ) { $env = SPX_Order_Environment::get( $order ); if ( '' !== $env ) { $groups[$env][] = $order; } }
				foreach ( $groups as $environment => $orders ) {
					$summary = $sync->sync_orders( $orders, 'scheduler', 0 );
					$health['queried'] += (int) $summary['queried']; $health['updated'] += (int) $summary['updated']; $health['retry'] += count( $summary['retry'] );
					if ( $summary['fatal'] ) { $health['failed']++; $health['last_error'] = sanitize_key( (string) ( $summary['fatal']['error_code'] ?? 'api_error' ) ) . ':' . (string) (int) ( $summary['fatal']['ret_code'] ?? 0 ); continue; }
					foreach ( $summary['retry'] as $order_id ) { self::schedule_retry( (int) $order_id, 1, $environment ); }
				}
				++$page;
			} while ( $page <= $result['max_num_pages'] );
			$health['skipped'] = max( 0, $health['queried'] - $health['updated'] - $health['retry'] ); if ( '' === $health['last_error'] ) { $health['last_success'] = gmdate( 'c' ); }
			update_option( 'spx_tracking_last_run', gmdate( 'c' ), false );
			update_option( 'spx_tracking_health', $health, false );
		} finally { delete_option( self::LOCK ); }
	}

	public static function run_retry( int $order_id, int $retry_count, string $environment = 'test' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || SPX_Order_Environment::get( $order ) !== SPX_Environment::normalize( $environment ) ) { return; }
		$summary = ( new SPX_Tracking_Sync_Service() )->sync_orders( array( $order ), 'scheduler_retry', $retry_count );
		if ( $summary['retry'] && $retry_count < 3 ) { self::schedule_retry( $order_id, $retry_count + 1, $environment ); }
	}

	public static function enqueue_now(): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) { return 0 !== as_enqueue_async_action( self::HOOK, array(), self::TEST_GROUP, false ); }
		return false !== wp_schedule_single_event( time() + 1, 'spx_tracking_cron' );
	}

	private static function schedule_retry( int $order_id, int $count, string $environment ): void {
		$delays = array( 1 => 5, 2 => 15, 3 => 30 ); $when = time() + $delays[ $count ] * MINUTE_IN_SECONDS;
		$args = array( $order_id, $count, $environment ); $group = 'production' === $environment ? self::PRODUCTION_GROUP : self::TEST_GROUP;
		if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( $when, self::RETRY_HOOK, $args, $group, true ); }
		else { wp_schedule_single_event( $when, self::RETRY_HOOK, $args ); }
	}

	public static function schedule_canonical_verification( int $order_id ): void {
		$order = wc_get_order( $order_id ); $environment = $order ? SPX_Order_Environment::get( $order ) : '';
		if ( '' === $environment ) { return; }
		$args = array( $order_id, 0, $environment ); $group = 'production' === $environment ? self::PRODUCTION_GROUP : self::TEST_GROUP;
		if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + 60, self::RETRY_HOOK, $args, $group, true ); }
		elseif ( ! wp_next_scheduled( self::RETRY_HOOK, $args ) ) { wp_schedule_single_event( time() + 60, self::RETRY_HOOK, $args ); }
	}

	public static function acquire_lock(): bool {
		$now = time(); $lock = (int) get_option( self::LOCK, 0 );
		if ( $lock && $lock > $now - 300 ) { return false; }
		if ( $lock ) { delete_option( self::LOCK ); }
		return add_option( self::LOCK, $now, '', false );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) { foreach ( array( self::GROUP, self::TEST_GROUP, self::PRODUCTION_GROUP ) as $group ) { as_unschedule_all_actions( self::HOOK, array(), $group ); as_unschedule_all_actions( self::RETRY_HOOK, null, $group ); } }
		wp_clear_scheduled_hook( 'spx_tracking_cron' );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	public static function deactivate(): void { self::unschedule(); delete_option( self::LOCK ); }
	public static function cron_schedules( array $schedules ): array {
		foreach ( array( 300, 900, 1800, 3600 ) as $seconds ) { $schedules[ 'spx_tracking_' . $seconds ] = array( 'interval' => $seconds, 'display' => 'SPX tracking ' . $seconds ); }
		return $schedules;
	}
}
