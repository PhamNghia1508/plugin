<?php
defined( 'ABSPATH' ) || exit;
final class SPX_Environment_Router {
	public static function single_batch_environment( array $orders ): string {
		$environment = '';
		foreach ( $orders as $order ) { $current = SPX_Order_Environment::get( $order ); if ( '' === $current || ( '' !== $environment && $environment !== $current ) ) { return ''; } $environment = $current; }
		return $environment;
	}
	public static function production_operation_allowed( string $operation ): bool { return false; }

	public static function config( string $environment ): SPX_API_Config { return SPX_API_Config::for_environment( SPX_Environment::normalize( $environment ) ); }

	public static function tracking_service( string $environment ) {
		$environment = SPX_Environment::normalize( $environment );
		if ( SPX_Environment::PRODUCTION === $environment ) { return null; }
		return SPX_Environment::TEST === $environment ? new SPX_Tracking_Service( self::config( $environment ) ) : null;
	}
}
