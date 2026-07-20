<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/includes/address/class-supership-address-normalizer.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

// normalize(): case/space/punctuation folded, but diacritics are KEPT (not ASCII-folded).
expect_same(
	SuperShip_Address_Normalizer::normalize( 'Hồ Chí Minh' ),
	SuperShip_Address_Normalizer::normalize( '  hồ   chí minh ' ),
	'normalize() must fold case/whitespace but keep diacritics identical for equivalent input'
);

// loose_key(): strips administrative prefix + folds diacritics to ASCII.
expect_same( 'ho chi minh', SuperShip_Address_Normalizer::loose_key( 'Thành phố Hồ Chí Minh' ), 'loose_key() must strip "thanh pho" prefix and fold diacritics' );
expect_same( 'da nang', SuperShip_Address_Normalizer::loose_key( 'Thành phố Đà Nẵng' ), 'loose_key() must fold đ/ẵ correctly' );
expect_same( 'tan binh', SuperShip_Address_Normalizer::loose_key( 'Quận Tân Bình' ), 'loose_key() must strip "quan" prefix' );
expect_same( 'binh chanh', SuperShip_Address_Normalizer::loose_key( 'Huyện Bình Chánh' ), 'loose_key() must strip "huyen" prefix' );
expect_same( '14', SuperShip_Address_Normalizer::loose_key( 'Phường 14' ), 'loose_key() must strip "phuong" prefix even for numeric wards' );
expect_same( 'ngoc khanh', SuperShip_Address_Normalizer::loose_key( 'Phường Ngọc Khánh' ), 'loose_key() must strip "phuong" prefix and fold' );

// fold(): deterministic Vietnamese -> ASCII, no locale dependency.
expect_same( 'da nang', SuperShip_Address_Normalizer::fold( 'da nang' ), 'fold() on already-ASCII text is a no-op' );
expect_same( 'ho chi minh', SuperShip_Address_Normalizer::fold( 'hồ chí minh' ), 'fold() must map ồ->o, í->i' );
expect_same( 'duong', SuperShip_Address_Normalizer::fold( 'đương' ), 'fold() must map đ->d and ươ->uo' );

// Same administrative name with different formatting must collapse to the same loose_key
// (this is the actual guarantee SuperShip_Address_Repository::fuzzy_match() relies on).
expect_same(
	SuperShip_Address_Normalizer::loose_key( 'TP. Hồ Chí Minh' ),
	SuperShip_Address_Normalizer::loose_key( 'Thành phố Hồ Chí Minh' ),
	'loose_key() must treat "TP." and "Thành phố" prefixes as equivalent'
);

echo "All SuperShip_Address_Normalizer tests passed.\n";
