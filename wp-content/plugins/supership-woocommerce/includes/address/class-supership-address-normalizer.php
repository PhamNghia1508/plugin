<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Address Normalizer
 * 
 * Normalizes Vietnamese administrative names for fuzzy matching.
 * 
 * This class is carrier-independent and copied from SPX implementation
 * with excellent Vietnamese diacritic handling logic.
 * 
 * Two normalization levels:
 * 1. normalize(): Case/space/punctuation folded, diacritics KEPT (strict matching)
 * 2. loose_key(): normalize() + unit prefix stripped + diacritics folded to ASCII (fuzzy matching)
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Address_Normalizer {

	/**
	 * Vietnamese administrative unit prefixes
	 * Ordered longest-first for correct multi-word prefix stripping
	 */
	private static function prefixes(): array {
		return array(
			'thanh pho thuoc tinh',
			'thanh pho',
			'thi tran',
			'thi xa',
			'tinh',
			'quan',
			'huyen',
			'phuong',
			'xa',
			'tp.',
			'tp',
		);
	}

	/**
	 * Normalize address name (strict)
	 * 
	 * Keeps diacritics, folds case/space/punctuation.
	 * Use for exact matching with normalized keys.
	 * 
	 * @param string $value Raw address name
	 * @return string Normalized name
	 */
	public static function normalize( string $value ): string {
		$value = self::mb_lower( trim( $value ) );
		// Remove punctuation
		$value = str_replace( array( '.', ',', '-', '_', '(', ')', '/', '\\' ), ' ', $value );
		// Collapse multiple spaces
		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Generate loose key (fuzzy matching)
	 * 
	 * Strips unit prefixes, folds diacritics to ASCII.
	 * Use for fallback matching when strict match fails.
	 * 
	 * Example:
	 * - Input: "Thành phố Hồ Chí Minh"
	 * - Output: "ho chi minh"
	 * 
	 * @param string $value Raw address name
	 * @return string Loose key
	 */
	public static function loose_key( string $value ): string {
		$value = self::fold( self::normalize( $value ) );
		
		// Strip administrative unit prefix
		foreach ( self::prefixes() as $prefix ) {
			if ( 0 === strpos( $value, $prefix . ' ' ) ) {
				$value = trim( substr( $value, strlen( $prefix ) + 1 ) );
				break; // Only strip first match
			}
		}
		
		// Collapse spaces again
		return preg_replace( '/\s+/', ' ', trim( $value ) );
	}

	/**
	 * Fold Vietnamese diacritics to ASCII
	 * 
	 * Deterministic, no locale dependency.
	 * 
	 * Examples:
	 * - "Hồ Chí Minh" → "ho chi minh"
	 * - "Đà Nẵng" → "da nang"
	 * 
	 * @param string $value String with Vietnamese diacritics
	 * @return string ASCII-folded string
	 */
	public static function fold( string $value ): string {
		$map = self::fold_map();
		return strtr( $value, $map );
	}

	/**
	 * Multibyte-safe lowercase
	 */
	private static function mb_lower( string $value ): string {
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );
	}

	/**
	 * Vietnamese diacritic to ASCII mapping
	 * 
	 * Covers all common Vietnamese characters with tones.
	 * 
	 * @return array Character mapping
	 */
	private static function fold_map(): array {
		static $map = null;
		
		if ( null !== $map ) {
			return $map;
		}
		
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
			foreach ( $chars as $ch ) {
				$map[ $ch ] = $ascii;
			}
		}
		
		return $map;
	}
}
