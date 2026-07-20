<?php
defined( 'ABSPATH' ) || exit;

/**
 * Normalises Vietnamese administrative names for MATCHING only. It never mutates
 * the official display name stored in the dataset. Two keys are produced:
 *   - normalize(): case/space/punctuation-folded, diacritics + unit prefix KEPT
 *     (the strict key; distinct entities stay distinct).
 *   - loose_key(): normalize() + unit prefix stripped + diacritics folded
 *     (a looser key used only for a UNIQUE fallback match; ambiguity is rejected
 *     by the repository, never auto-picked).
 */
final class SPX_Address_Normalizer {

	/** Unit prefixes, longest first so multi-word prefixes strip correctly. */
	private static function prefixes(): array {
		return array(
			'thanh pho thuoc tinh', 'thanh pho', 'thi tran', 'thi xa',
			'tinh', 'quan', 'huyen', 'phuong', 'xa', 'tp.', 'tp',
		);
	}

	public static function normalize( string $value ): string {
		$value = self::mb_lower( trim( $value ) );
		$value = str_replace( array( '.', ',', '-', '_', '(', ')', '/', '\\' ), ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( (string) $value );
	}

	public static function loose_key( string $value ): string {
		$value = self::fold( self::normalize( $value ) );
		foreach ( self::prefixes() as $prefix ) {
			if ( 0 === strpos( $value, $prefix . ' ' ) ) {
				$value = trim( substr( $value, strlen( $prefix ) + 1 ) );
				break;
			}
		}
		return preg_replace( '/\s+/', ' ', trim( $value ) );
	}

	/** Fold Vietnamese diacritics to ASCII (deterministic, no locale dependency). */
	public static function fold( string $value ): string {
		$map = self::fold_map();
		return strtr( $value, $map );
	}

	private static function mb_lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private static function fold_map(): array {
		static $map = null;
		if ( null !== $map ) { return $map; }
		$groups = array(
			'a' => array( 'à', 'á', 'ả', 'ã', 'ạ', 'ă', 'ằ', 'ắ', 'ẳ', 'ẵ', 'ặ', 'â', 'ầ', 'ấ', 'ẩ', 'ẫ', 'ậ' ),
			'e' => array( 'è', 'é', 'ẻ', 'ẽ', 'ẹ', 'ê', 'ề', 'ế', 'ể', 'ễ', 'ệ' ),
			'i' => array( 'ì', 'í', 'ỉ', 'ĩ', 'ị' ),
			'o' => array( 'ò', 'ó', 'ỏ', 'õ', 'ọ', 'ô', 'ồ', 'ố', 'ổ', 'ỗ', 'ộ', 'ơ', 'ờ', 'ớ', 'ở', 'ỡ', 'ợ' ),
			'u' => array( 'ù', 'ú', 'ủ', 'ũ', 'ụ', 'ư', 'ừ', 'ứ', 'ử', 'ữ', 'ự' ),
			'y' => array( 'ỳ', 'ý', 'ỷ', 'ỹ', 'ỵ' ),
			'd' => array( 'đ' ),
		);
		$map = array();
		foreach ( $groups as $ascii => $chars ) {
			foreach ( $chars as $ch ) { $map[ $ch ] = $ascii; }
		}
		return $map;
	}
}
