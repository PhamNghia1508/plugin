<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Tracking_Event_Repository {
	const SCHEMA_VERSION = '2';
	const OPTION_VERSION = 'spx_tracking_event_schema_version';

	/** @var wpdb */ private $db;
	/** @var string */ private $table;

	public function __construct( $db = null ) {
		global $wpdb;
		$this->db    = $db ?: $wpdb;
		$this->table = $this->db->prefix . 'spx_tracking_events';
	}

	public static function install(): void {
		global $wpdb;
		if ( get_option( self::OPTION_VERSION ) === self::SCHEMA_VERSION ) { return; }
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'spx_tracking_events';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			tracking_no varchar(48) NOT NULL,
			event_key char(64) NOT NULL,
			event_id varchar(128) NOT NULL DEFAULT '',
			status_code varchar(16) NOT NULL,
			official_status varchar(191) NOT NULL DEFAULT '',
			internal_status varchar(40) NOT NULL,
			customer_label varchar(191) NOT NULL,
			customer_message varchar(500) NOT NULL DEFAULT '',
			event_time datetime NOT NULL,
			event_timestamp bigint(20) unsigned NOT NULL,
			is_stale tinyint(1) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL,
			received_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY order_time (order_id,event_timestamp),
			KEY tracking_time (tracking_no,event_timestamp)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function event_key( array $event ): string {
		$official_id = trim( (string) ( $event['event_id'] ?? '' ) );
		if ( '' !== $official_id ) { return hash( 'sha256', 'id|' . $official_id ); }
		$parts = array(
			strtoupper( trim( (string) ( $event['tracking_no'] ?? '' ) ) ),
			(string) ( $event['status_code'] ?? '' ),
			(string) (int) ( $event['event_timestamp'] ?? 0 ),
			trim( (string) ( $event['customer_message'] ?? '' ) ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	public function insert( array $event ): array {
		$allowed = array(
			'order_id' => absint( $event['order_id'] ?? 0 ),
			'tracking_no' => substr( sanitize_text_field( (string) ( $event['tracking_no'] ?? '' ) ), 0, 48 ),
			'event_key' => self::event_key( $event ),
			'event_id' => substr( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 0, 128 ),
			'status_code' => substr( sanitize_text_field( (string) ( $event['status_code'] ?? '' ) ), 0, 16 ),
			'official_status' => substr( sanitize_text_field( (string) ( $event['official_status'] ?? '' ) ), 0, 191 ),
			'internal_status' => substr( sanitize_key( (string) ( $event['internal_status'] ?? 'unknown' ) ), 0, 40 ),
			'customer_label' => substr( sanitize_text_field( (string) ( $event['customer_label'] ?? '' ) ), 0, 191 ),
			'customer_message' => substr( sanitize_text_field( (string) ( $event['customer_message'] ?? '' ) ), 0, 500 ),
			'event_time' => gmdate( 'Y-m-d H:i:s', (int) ( $event['event_timestamp'] ?? 0 ) ),
			'event_timestamp' => max( 0, (int) ( $event['event_timestamp'] ?? 0 ) ),
			'is_stale' => empty( $event['is_stale'] ) ? 0 : 1,
			'source' => substr( sanitize_key( (string) ( $event['source'] ?? 'search' ) ), 0, 20 ),
			'received_at' => gmdate( 'Y-m-d H:i:s' ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( ! $allowed['order_id'] || ! SPX_Tracking_Service::is_real_tracking( $allowed['tracking_no'] ) || ! $allowed['event_timestamp'] ) {
			return array( 'inserted' => false, 'duplicate' => false, 'error' => 'invalid_event' );
		}
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$this->table} (order_id,tracking_no,event_key,event_id,status_code,official_status,internal_status,customer_label,customer_message,event_time,event_timestamp,is_stale,source,received_at,created_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s)",
				$allowed['order_id'], $allowed['tracking_no'], $allowed['event_key'], $allowed['event_id'], $allowed['status_code'], $allowed['official_status'], $allowed['internal_status'], $allowed['customer_label'], $allowed['customer_message'], $allowed['event_time'], $allowed['event_timestamp'], $allowed['is_stale'], $allowed['source'], $allowed['received_at'], $allowed['created_at']
			)
		);
		return array( 'inserted' => 1 === $result, 'duplicate' => 0 === $result, 'error' => false === $result ? 'database_error' : '' );
	}

	public function get_by_order( int $order_id, int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$rows = $this->db->get_results( $this->db->prepare( "SELECT status_code,internal_status,customer_label,customer_message,event_timestamp,is_stale,source FROM {$this->table} WHERE order_id=%d ORDER BY event_timestamp DESC,id DESC LIMIT %d", $order_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function count_by_order( int $order_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE order_id=%d", $order_id ) );
	}

	public function get_by_tracking( string $tracking_no, int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$rows = $this->db->get_results( $this->db->prepare( "SELECT status_code,official_status,internal_status,customer_label,customer_message,event_timestamp,is_stale,source FROM {$this->table} WHERE tracking_no=%s ORDER BY event_timestamp DESC,id DESC LIMIT %d", $tracking_no, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
