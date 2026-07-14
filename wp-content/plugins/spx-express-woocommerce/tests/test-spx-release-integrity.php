<?php
declare( strict_types=1 );

/** Immutable release-source contract. No WordPress bootstrap or network access. */

$root     = dirname( __DIR__ );
$failures = 0;
$checks   = 0;

function spx_integrity_check( bool $condition, string $message ): void {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
		return;
	}
	echo "PASS: {$message}\n";
}

function spx_integrity_read( string $path ): string {
	$content = file_get_contents( $path );
	if ( false === $content ) {
		throw new RuntimeException( 'Unable to read release source: ' . basename( $path ) );
	}
	return $content;
}

$main       = spx_integrity_read( $root . '/spx-express-woocommerce.php' );
$blocks     = spx_integrity_read( $root . '/assets/js/blocks-checkout-address.js' );
$resolver   = spx_integrity_read( $root . '/includes/class-spx-payment-resolver.php' );
$mapper     = spx_integrity_read( $root . '/includes/class-spx-order-mapper.php' );
$readiness  = spx_integrity_read( $root . '/includes/class-spx-shipment-readiness.php' );
$service    = spx_integrity_read( $root . '/includes/api/class-spx-shipment-service.php' );
$create_map = spx_integrity_read( $root . '/includes/api/class-spx-create-request-mapper.php' );
$shipping   = spx_integrity_read( $root . '/includes/class-spx-shipping-method.php' );
$fallback   = spx_integrity_read( $root . '/includes/rate/class-spx-dynamic-checkout-rate-service.php' );
$admin      = spx_integrity_read( $root . '/includes/admin/class-spx-admin-shipment.php' );
$readme     = spx_integrity_read( $root . '/README.md' );
$guide      = spx_integrity_read( $root . '/USER-GUIDE-vi.md' );
$changelog  = spx_integrity_read( $root . '/CHANGELOG.md' );
$pot        = spx_integrity_read( $root . '/languages/spx-express-woocommerce.pot' );
$po         = spx_integrity_read( $root . '/languages/spx-express-woocommerce-vi.po' );

spx_integrity_check( 1 === preg_match( '/^[ \t]*\*[ \t]+Version:[ \t]+0\.9\.0-rc\.5[ \t]*$/m', $main ), 'plugin header is 0.9.0-rc.5' );
spx_integrity_check( false !== strpos( $main, "define( 'SPX_WC_VERSION', '0.9.0-rc.5' );" ), 'runtime version is 0.9.0-rc.5' );
spx_integrity_check( false !== strpos( $blocks, "version: '0.9.0-rc.5'" ), 'Blocks integration version is 0.9.0-rc.5' );
spx_integrity_check( false !== strpos( $readme, '`0.9.0-rc.5`' ), 'README version is 0.9.0-rc.5' );
spx_integrity_check( false !== strpos( $guide, 'spx-express-woocommerce-0.9.0-rc.5.zip' ), 'Vietnamese guide uses the rc.4 package name' );
spx_integrity_check( false !== strpos( $changelog, '## 0.9.0-rc.5' ), 'changelog starts the rc.4 release record' );
spx_integrity_check( false !== strpos( $pot, '0.9.0-rc.5' ) && false !== strpos( $po, '0.9.0-rc.5' ), 'translation catalog headers use rc.4' );
spx_integrity_check( false === strpos( $blocks, "category: 'woocommerce'" ), 'Blocks frontend metadata does not use the editor-only WooCommerce category' );

$text_extensions = array( 'php', 'js', 'css', 'md', 'po', 'pot' );
$bad_sequences   = array(
	"\xC3\x83",             // U+00C3: UTF-8 decoded as Windows-1252.
	"\xC3\x82",             // U+00C2: stray double-encoding marker.
	"\xC3\x84",             // U+00C4: prefix in corrupt Vietnamese strings.
	"\xC3\x86",             // U+00C6: prefix in corrupt Vietnamese strings.
	"\xC3\xA1\xC2\xBA", // Corrupt Vietnamese combining sequence.
	"\xC3\xA1\xC2\xBB", // Corrupt Vietnamese combining sequence.
	"\xC3\xA2\xE2\x82\xAC", // Corrupt smart punctuation prefix.
);
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || false !== strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	if ( ! in_array( strtolower( $file->getExtension() ), $text_extensions, true ) ) {
		continue;
	}
	$content = spx_integrity_read( $file->getPathname() );
	$clean   = true;
	foreach ( $bad_sequences as $sequence ) {
		if ( false !== strpos( $content, $sequence ) ) {
			$clean = false;
			break;
		}
	}
	spx_integrity_check( $clean, 'UTF-8 source is clean: ' . str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() ) );
}

spx_integrity_check( false !== strpos( $resolver, 'final class SPX_Payment_Resolver' ), 'canonical payment resolver is present' );
foreach ( array( 'payment_method', 'is_paid', 'payment_state', 'cod_amount', 'ready_for_shipment', 'reason' ) as $key ) {
	spx_integrity_check( false !== strpos( $resolver, "'{$key}'" ), 'payment resolver owns ' . $key );
}
spx_integrity_check( false !== strpos( $mapper, 'SPX_Payment_Resolver::resolve( $order )' ), 'order mapper consumes canonical payment resolver' );
spx_integrity_check( false !== strpos( $readiness, "\$payment['ready_for_shipment']" ), 'readiness consumes canonical payment readiness' );
spx_integrity_check( false !== strpos( $service, "true !== \$s['ready_for_shipment']" ), 'direct Create service fails closed on payment readiness' );
spx_integrity_check( false !== strpos( $create_map, "! array_key_exists( 'cod_amount', \$s )" ), 'Create mapper rejects missing COD amount' );

spx_integrity_check( false !== strpos( $shipping, 'settings_cache_signature' ), 'matched-instance cache signature is present' );
spx_integrity_check( false !== strpos( $shipping, 'WC_Shipping_Zones::get_zone_matching_package( $package )' ), 'shipping-zone matching is canonical' );
spx_integrity_check( false !== strpos( $shipping, "'spx_shipping_instance_signature'" ), 'package cache includes matched shipping instance signature' );
spx_integrity_check( false !== strpos( $shipping, "\$candidate <= 0.0" ), 'dynamic zero rate fails closed' );
spx_integrity_check( false !== strpos( $fallback, "'fixed_unconfigured'" ), 'unavailable dynamic fallback cannot create a free rate' );

spx_integrity_check( false !== strpos( $admin, 'acquire_create_lock' ), 'Create path uses an explicit lock' );
spx_integrity_check( false !== strpos( $admin, 'return add_option( self::LOCK_PREFIX' ), 'Create lock acquisition is atomic at the database boundary' );
spx_integrity_check( false !== strpos( $admin, 'create_guard_reason' ), 'durable Create attempt guard is present' );

if ( 0 !== $failures ) {
	fwrite( STDERR, "SPX release integrity FAILED ({$failures}/{$checks}).\n" );
	exit( 1 );
}

echo "SPX release integrity PASS ({$checks} checks).\n";
