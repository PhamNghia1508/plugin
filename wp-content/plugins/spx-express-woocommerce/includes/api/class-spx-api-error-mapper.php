<?php
defined( 'ABSPATH' ) || exit;

/**
 * Maps SPX ret_code values to a stable category, retryability, and a safe
 * (non-raw) admin message. Never surfaces SPX debug_msg text.
 */
final class SPX_API_Error_Mapper {
	const CAT_AUTH       = 'authentication';
	const CAT_VALIDATION = 'validation';
	const CAT_DUPLICATE  = 'duplicate';
	const CAT_NOT_FOUND  = 'not_found';
	const CAT_TEMPORARY  = 'temporary';
	const CAT_PERMANENT  = 'permanent';
	const CAT_TRANSPORT  = 'transport';

	/** @return array<int,array{0:string,1:bool}> ret_code => [category, retryable] */
	private static function table() {
		return array(
			1001  => array( self::CAT_AUTH, false ),
			1002  => array( self::CAT_DUPLICATE, false ),
			1003  => array( self::CAT_AUTH, false ),
			1004  => array( self::CAT_AUTH, false ),
			1005  => array( self::CAT_AUTH, false ),
			1006  => array( self::CAT_AUTH, false ),
			1007  => array( self::CAT_AUTH, false ),
			1008  => array( self::CAT_VALIDATION, false ),
			1009  => array( self::CAT_AUTH, false ),
			11001 => array( self::CAT_VALIDATION, false ),
			12051 => array( self::CAT_AUTH, false ),
			12052 => array( self::CAT_AUTH, false ),
			13051 => array( self::CAT_VALIDATION, false ),
			13002 => array( self::CAT_PERMANENT, false ),
			13101 => array( self::CAT_PERMANENT, false ),
			13103 => array( self::CAT_DUPLICATE, false ),
			13201 => array( self::CAT_PERMANENT, false ),
			13251 => array( self::CAT_NOT_FOUND, false ),
			99001 => array( self::CAT_TEMPORARY, true ),
			99004 => array( self::CAT_TEMPORARY, true ),
		);
	}

	public static function category( int $ret_code ): string {
		$table = self::table();
		return isset( $table[ $ret_code ] ) ? $table[ $ret_code ][0] : self::CAT_PERMANENT;
	}

	public static function is_retryable( int $ret_code ): bool {
		$table = self::table();
		return isset( $table[ $ret_code ] ) ? (bool) $table[ $ret_code ][1] : false;
	}

	public static function safe_message( int $ret_code ): string {
		switch ( self::category( $ret_code ) ) {
			case self::CAT_AUTH:
				return __( 'SPX rejected the request authentication or account credentials.', 'spx-express-woocommerce' );
			case self::CAT_VALIDATION:
				return __( 'SPX rejected the request because one or more fields are invalid.', 'spx-express-woocommerce' );
			case self::CAT_DUPLICATE:
				return __( 'SPX reported this request or order as a duplicate.', 'spx-express-woocommerce' );
			case self::CAT_NOT_FOUND:
				return __( 'SPX could not find the requested order.', 'spx-express-woocommerce' );
			case self::CAT_TEMPORARY:
				return __( 'SPX is temporarily unavailable. Please try again later.', 'spx-express-woocommerce' );
			default:
				return __( 'The SPX request could not be completed.', 'spx-express-woocommerce' );
		}
	}
}
