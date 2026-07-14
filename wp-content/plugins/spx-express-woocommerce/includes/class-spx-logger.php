<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Logger {
	public static function log( string $level, string $event, int $order_id = 0, string $tracking = '', string $error = '' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) { return; }
		$message = wp_json_encode( array_filter( array(
			'event' => sanitize_key( $event ), 'order_id' => absint( $order_id ),
			'tracking_number' => sanitize_text_field( $tracking ), 'error' => self::redact( $error ),
		) ) );
		wc_get_logger()->log( in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info', $message, array( 'source' => 'spx-express' ) );
	}

	public static function redact( string $message ): string {
		$message = preg_replace( '/(["\']?(?:authorization|api[_ -]?secret|client[_ -]?id|access[_ -]?token|token|password)["\']?\s*[:=]\s*)(?:bearer\s+)?["\']?[^"\'\s,;]+["\']?/i', '$1[redacted]', $message );
		$message = preg_replace( '/(?<!\d)\+?\d[\d .-]{7,}\d(?!\d)/', '[redacted-phone]', (string) $message );
		return sanitize_text_field( (string) $message );
	}
}
