<?php
/** Phase 6R-B generated asset metadata contract (PHP 7.4 compatible). */
error_reporting( E_ALL );

function spx_blocks_metadata_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$root       = dirname( __DIR__ );
$asset_path = $root . '/assets/js/blocks-checkout-address.asset.php';
$bundle     = $root . '/assets/js/blocks-checkout-address.js';
$php        = file_get_contents( $root . '/includes/checkout/class-spx-blocks-checkout-integration.php' );

spx_blocks_metadata_assert( is_file( $asset_path ), 'asset metadata exists beside the built bundle' );
$asset = require $asset_path;
spx_blocks_metadata_assert( is_array( $asset ), 'asset metadata returns an array' );
spx_blocks_metadata_assert( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ), 'asset metadata declares dependencies' );
spx_blocks_metadata_assert( isset( $asset['version'] ) && is_string( $asset['version'] ) && '' !== $asset['version'], 'asset metadata declares a non-empty version' );

$required = array( 'wp-element', 'wc-settings', 'wc-blocks-checkout', 'wc-blocks-data-store' );
foreach ( $required as $dependency ) {
	spx_blocks_metadata_assert( in_array( $dependency, $asset['dependencies'], true ), 'asset metadata includes ' . $dependency );
}
spx_blocks_metadata_assert( count( $asset['dependencies'] ) === count( array_unique( $asset['dependencies'] ) ), 'asset dependencies contain no duplicates' );
spx_blocks_metadata_assert( hash_file( 'sha256', $bundle ) === $asset['version'], 'asset version matches the built bundle hash' );
spx_blocks_metadata_assert( false !== strpos( $php, 'blocks-checkout-address.asset.php' ), 'PHP loads the generated asset metadata' );
spx_blocks_metadata_assert( false !== strpos( $php, "\$asset['dependencies']" ), 'PHP consumes generated dependencies' );
spx_blocks_metadata_assert( false !== strpos( $php, "\$asset['version']" ), 'PHP consumes generated version' );
spx_blocks_metadata_assert( false === strpos( $php, "filemtime( SPX_WC_PATH . 'assets/js/blocks-checkout-address.js'" ), 'PHP does not maintain a second bundle-version source' );

echo "\nAll Phase 6R-B build metadata assertions passed.\n";

