<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Environment {
	const TEST = 'test';
	const PRODUCTION = 'production';
	const TEST_BASE_URL = 'https://test-stable.spx.vn/';
	const PRODUCTION_BASE_URL = 'https://spx.vn/';

	private const ENDPOINTS = array(
		'/open/api/v1/account/verify',
		'/open/api/v1/order/batch_check_order',
		'/open/api/v1/order/batch_create_order',
		'/open/api/v1/order/batch_search_order',
		'/open/api/v1/order/batch_get_shipping_label',
		'/open/api/v1/order/batch_cancel_order',
	);

	public static function normalize( $environment ): string {
		$value = strtolower( trim( (string) $environment ) );
		return in_array( $value, array( self::TEST, self::PRODUCTION ), true ) ? $value : '';
	}

	public static function base_url( $environment ): string {
		$environment = self::normalize( $environment );
		if ( self::TEST === $environment ) { return self::TEST_BASE_URL; }
		if ( self::PRODUCTION === $environment ) { return self::PRODUCTION_BASE_URL; }
		return '';
	}

	public static function host( $environment ): string {
		return self::TEST === self::normalize( $environment ) ? 'test-stable.spx.vn' : ( self::PRODUCTION === self::normalize( $environment ) ? 'spx.vn' : '' );
	}

	public static function is_allowed_endpoint( $path ): bool {
		return is_string( $path ) && in_array( $path, self::ENDPOINTS, true );
	}

	public static function endpoints(): array { return self::ENDPOINTS; }
}
