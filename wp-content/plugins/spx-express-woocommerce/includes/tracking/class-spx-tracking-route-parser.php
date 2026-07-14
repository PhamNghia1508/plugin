<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Tracking_Route_Parser {
	public static function parse( array $routes, string $tracking_no, string $source = 'search' ): array {
		$out = array();
		foreach ( $routes as $route ) {
			$timestamp = self::timestamp( $route['timestamp'] ?? 0 );
			if ( ! $timestamp ) { continue; }
			$mapped = SPX_Tracking_Status_Mapper::map( $route['status_code'] ?? '', (string) ( $route['status'] ?? '' ) );
			$message = self::safe_message( (string) ( $route['customer_message'] ?? ( $route['message'] ?? '' ) ), $mapped['customer_label'] );
			$out[] = array(
				'tracking_no' => $tracking_no,
				'event_id' => substr( sanitize_text_field( (string) ( $route['event_id'] ?? '' ) ), 0, 128 ),
				'status_code' => $mapped['status_code'],
				'official_status' => $mapped['official_status'],
				'internal_status' => $mapped['internal_status'],
				'customer_label' => $mapped['customer_label'],
				'customer_message' => $message,
				'event_timestamp' => $timestamp,
				'terminal' => $mapped['terminal'],
				'source' => sanitize_key( $source ),
			);
		}
		usort( $out, static function ( $a, $b ) { return $a['event_timestamp'] <=> $b['event_timestamp']; } );
		return $out;
	}

	private static function timestamp( $value ): int {
		if ( ! is_numeric( $value ) ) { return 0; }
		$value = (int) $value;
		if ( $value > 9999999999 ) { $value = (int) floor( $value / 1000 ); }
		return ( $value >= 946684800 && $value <= time() + DAY_IN_SECONDS ) ? $value : 0;
	}

	private static function safe_message( string $message, string $fallback ): string {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) { return $fallback; }
		$suspicious = '/(?:\+?84|0)\d{8,10}|(?:debug|exception|stack|secret|signature|token|phone|address|địa chỉ|số điện thoại|ret_code|trace)/iu';
		if ( preg_match( $suspicious, $message ) ) { return $fallback; }
		return substr( sanitize_text_field( $message ), 0, 500 );
	}
}
