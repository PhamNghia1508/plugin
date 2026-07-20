<?php
defined( 'ABSPATH' ) || exit;

/** Immutable SPX → WooCommerce status contract plus allowlisted settings. */
final class SPX_Woo_Status_Mapping_Policy {
	const OPTION           = 'spx_woo_status_mapping_settings';
	const VERSION_OPTION   = 'spx_status_mapping_settings_version';
	const SETTINGS_VERSION = 1;

	public static function defaults(): array {
		return array(
			'master_enabled' => 'yes',
			'map_3001' => 'yes', 'map_4001' => 'yes', 'map_5001' => 'no',
			'map_5002' => 'yes', 'map_5003' => 'yes', 'map_6001' => 'yes',
			'map_6002' => 'no', 'map_6003' => 'no', 'map_7001' => 'no',
		);
	}

	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? self::sanitize( array_merge( self::defaults(), $stored ) ) : self::defaults();
	}

	/** Store only the schema version; this never scans or remaps an order. */
	public static function maybe_initialize() {
		if ( false === get_option( self::VERSION_OPTION, false ) ) {
			add_option( self::VERSION_OPTION, self::SETTINGS_VERSION, '', 'no' );
		}
	}

	/** Only known boolean switches survive; mapping targets never come from input. */
	public static function sanitize( array $input ): array {
		$out = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : 'no';
			$value = is_scalar( $raw ) ? (string) $raw : 'no';
			$out[ $key ] = in_array( $value, array( 'yes', '1', 'on' ), true ) ? 'yes' : 'no';
		}
		return $out;
	}

	public static function mapping( string $status_code, array $settings = null ): array {
		$status_code = sanitize_key( $status_code );
		$contract = self::contract();
		if ( ! isset( $contract[ $status_code ] ) ) {
			return array( 'code' => $status_code, 'target' => '', 'enabled' => false, 'allowed_sources' => array(), 'label' => 'Unknown' );
		}
		$mapping = $contract[ $status_code ];
		if ( '' === $mapping['target'] ) {
			$mapping['enabled'] = false;
			return $mapping;
		}
		$settings = null === $settings ? self::settings() : self::sanitize( array_merge( self::defaults(), $settings ) );
		$mapping['enabled'] = 'yes' === $settings['master_enabled'] && 'yes' === $settings[ 'map_' . $status_code ];
		return $mapping;
	}

	public static function contract(): array {
		$delivery = array( 'pending', 'on-hold', 'processing' );
		$exception = array( 'pending', 'processing' );
		return array(
			'1001' => self::row( '1001', '', array(), 'Pending Pickup' ),
			'2001' => self::row( '2001', '', array(), 'In Transit' ),
			'2006' => self::row( '2006', '', array(), 'Delivering' ),
			'3001' => self::row( '3001', 'on-hold', $exception, 'On Hold' ),
			'4001' => self::row( '4001', 'completed', $delivery, 'Delivered' ),
			'5001' => self::row( '5001', 'on-hold', $exception, 'Pickup Failed' ),
			'5002' => self::row( '5002', 'on-hold', $exception, 'Damaged' ),
			'5003' => self::row( '5003', 'on-hold', $exception, 'Lost' ),
			'6001' => self::row( '6001', 'on-hold', $exception, 'Returning' ),
			'6002' => self::row( '6002', 'on-hold', $exception, 'Return Failed' ),
			'6003' => self::row( '6003', 'on-hold', $exception, 'Returned' ),
			'7001' => self::row( '7001', 'cancelled', $delivery, 'Cancelled' ),
		);
	}

	private static function row( string $code, string $target, array $allowed, string $label ): array {
		return array( 'code' => $code, 'target' => $target, 'enabled' => false, 'allowed_sources' => $allowed, 'label' => $label );
	}
}
