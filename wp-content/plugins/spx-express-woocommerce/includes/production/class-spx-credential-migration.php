<?php
defined( 'ABSPATH' ) || exit;
final class SPX_Credential_Migration {
	const VERSION = 1;
	public static function migrate_array( array $options, SPX_Secret_Storage $storage ): array {
		$map = array(
			'test' => array( 'spx_test_app_id' => 'app_id', 'spx_test_app_secret' => 'app_secret', 'spx_test_user_id' => 'user_id', 'spx_test_user_secret' => 'user_secret' ),
			'production' => array( 'spx_production_app_id' => 'app_id', 'spx_production_app_secret' => 'app_secret', 'spx_production_user_id' => 'user_id', 'spx_production_user_secret' => 'user_secret', 'spx_production_shop_id' => 'shop_id' ),
		);
		foreach ( $map as $env => $fields ) {
			$target = 'spx_credentials_' . $env; $record = isset( $options[ $target ] ) && is_array( $options[ $target ] ) ? $options[ $target ] : array();
			foreach ( $fields as $legacy => $field ) {
				if ( ! isset( $options[ $legacy ] ) || '' === (string) $options[ $legacy ] || isset( $record[ $field ] ) ) { continue; }
				$value = (string) $options[ $legacy];
				if ( in_array( $field, array( 'app_secret', 'user_secret' ), true ) ) { $plaintext = $value; $value = $storage->encrypt( $plaintext ); if ( '' === $value || $plaintext !== $storage->decrypt( $value ) ) { continue; } }
				$record[ $field ] = $value; unset( $options[ $legacy ] );
			}
			if ( $record ) { $options[ $target ] = $record; }
		}
		return $options;
	}

	public static function maybe_migrate(): void {
		if ( ! function_exists( 'get_option' ) || (int) get_option( 'spx_config_schema_version', 0 ) >= self::VERSION ) { return; }
		$legacy_keys = array( 'spx_test_app_id', 'spx_test_app_secret', 'spx_test_user_id', 'spx_test_user_secret', 'spx_production_app_id', 'spx_production_app_secret', 'spx_production_user_id', 'spx_production_user_secret', 'spx_production_shop_id' );
		$input = array(); foreach ( $legacy_keys as $key ) { $value = get_option( $key, null ); if ( null !== $value ) { $input[$key] = $value; } }
		$output = self::migrate_array( $input, new SPX_Secret_Storage() );
		foreach ( array( 'test', 'production' ) as $env ) { $key = 'spx_credentials_' . $env; if ( isset( $output[$key] ) && ! update_option( $key, $output[$key], false ) && get_option( $key, array() ) !== $output[$key] ) { return; } }
		foreach ( $legacy_keys as $key ) { if ( isset( $input[$key] ) && ! isset( $output[$key] ) ) { delete_option( $key ); } }
		update_option( 'spx_config_schema_version', self::VERSION, false );
	}
}
