<?php
/** Phase 6R-B static console-safety contract (PHP 7.4 compatible). */
error_reporting( E_ALL );

function spx_blocks_console_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$root   = dirname( __DIR__ );
$bundle = file_get_contents( $root . '/assets/js/blocks-checkout-address.js' );
$server = file_get_contents( $root . '/includes/checkout/class-spx-blocks-checkout-address.php' );

spx_blocks_console_assert( false === strpos( $bundle, 'wp.blocks.registerBlockType' ), 'frontend bundle does not manually register a WordPress block' );
spx_blocks_console_assert( ! preg_match( "/wp\\.blocks\\.registerBlockType[\\s\\S]*category:\\s*'woocommerce'/", $bundle ), 'frontend bundle has no manual registration with the editor-only WooCommerce category' );
spx_blocks_console_assert( false !== strpos( $server, "register_block_type( 'spx-express/shipping-address'" ), 'server remains the single WordPress block registration path' );
spx_blocks_console_assert( 1 === substr_count( $bundle, 'wc.blocksCheckout' ), 'bundle resolves the proxied Blocks API exactly once' );
spx_blocks_console_assert( false !== strpos( $bundle, 'var blocksCheckout = window.wc && window.wc.blocksCheckout;' ), 'bundle resolves and caches the declared Blocks API directly from the external script global' );
spx_blocks_console_assert( false !== strpos( $bundle, 'blocksCheckout.registerCheckoutBlock' ), 'Checkout block component registration remains enabled' );
spx_blocks_console_assert( false !== strpos( $bundle, 'blocksCheckout.registerCheckoutFilters' ), 'Checkout inner-block filter registration remains enabled' );
spx_blocks_console_assert( false !== strpos( $bundle, 'blocksCheckout.extensionCartUpdate' ), 'Store API cart recalculation remains enabled' );
spx_blocks_console_assert( false === strpos( $bundle, '__spxPhase6RB' ), 'diagnostic instrumentation is absent from the release bundle' );
spx_blocks_console_assert( false === strpos( $bundle, 'console.warn' ), 'bundle does not suppress or filter console warnings' );

echo "\nAll Phase 6R-B console contract assertions passed.\n";
