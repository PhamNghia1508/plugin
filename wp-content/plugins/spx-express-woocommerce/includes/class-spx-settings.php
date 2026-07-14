<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Settings {
	public static function get_instance_settings( int $instance_id = 0 ): array {
		$key   = $instance_id > 0 ? 'woocommerce_spx_express_' . $instance_id . '_settings' : 'woocommerce_spx_express_settings';
		$value = get_option( $key, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function provider( int $instance_id = 0 ): SPX_Shipping_Provider_Interface {
		$settings = self::get_instance_settings( $instance_id );
		return ( isset( $settings['environment'] ) && 'production' === $settings['environment'] ) ? SPX_Api_Provider::for_environment( 'production' ) : new SPX_Mock_Provider();
	}
}
