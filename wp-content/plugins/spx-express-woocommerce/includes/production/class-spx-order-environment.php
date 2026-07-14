<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SPX_Environment' ) ) { require_once __DIR__ . '/class-spx-environment.php'; }
final class SPX_Order_Environment {
	const META = '_spx_environment'; const LEGACY_META = '_spx_shipment_environment';
	public static function get( $order ): string {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { return ''; }
		$value = SPX_Environment::normalize( $order->get_meta( self::META, true ) );
		return '' !== $value ? $value : SPX_Environment::normalize( $order->get_meta( self::LEGACY_META, true ) );
	}
	public static function bind( $order, string $environment ): bool {
		$environment = SPX_Environment::normalize( $environment ); $current = self::get( $order );
		if ( '' === $environment || ( '' !== $current && $current !== $environment ) || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) { return false; }
		if ( '' === $current ) {
			foreach ( array( '_spx_tracking_number', '_spx_create_state', '_spx_label_state', '_spx_cancel_state' ) as $marker ) { if ( '' !== (string) $order->get_meta( $marker, true ) ) { return false; } }
		}
		$order->update_meta_data( self::META, $environment );
		if ( '' === (string) $order->get_meta( self::LEGACY_META, true ) ) { $order->update_meta_data( self::LEGACY_META, $environment ); }
		return true;
	}
}
