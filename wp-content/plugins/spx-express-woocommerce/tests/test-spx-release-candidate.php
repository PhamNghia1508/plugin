<?php
declare( strict_types=1 );

/** Release-candidate source contract. No WordPress bootstrap or network access. */

$root     = dirname( __DIR__ );
$failures = 0;

function spx_rc_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
		return;
	}
	echo "PASS: {$message}\n";
}

function spx_rc_read( string $path ): string {
	$content = file_get_contents( $path );
	if ( false === $content ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}
	return $content;
}

$main      = spx_rc_read( $root . '/spx-express-woocommerce.php' );
$bundle    = spx_rc_read( $root . '/assets/js/blocks-checkout-address.js' );
$changelog = spx_rc_read( $root . '/CHANGELOG.md' );
$fee       = spx_rc_read( $root . '/includes/rate/class-spx-fee-conversion-contract.php' );
$router    = spx_rc_read( $root . '/includes/production/class-spx-environment-router.php' );

spx_rc_check( 1 === preg_match( '/^[ \t]*\*[ \t]+Version:[ \t]+0\.9\.0-rc\.7[ \t]*$/m', $main ), 'plugin header uses 0.9.0-rc.7' );
spx_rc_check( false !== strpos( $main, "define( 'SPX_WC_VERSION', '0.9.0-rc.7' );" ), 'runtime version constant uses 0.9.0-rc.7' );
spx_rc_check( false !== strpos( $bundle, "version: '0.9.0-rc.7'" ), 'Checkout Blocks metadata uses the RC version' );
spx_rc_check( false !== strpos( $changelog, '0.9.0-rc.7' ), 'changelog contains the RC version' );
spx_rc_check( false === stripos( $changelog, 'Production Ready' ) && false === stripos( $changelog, 'Final Production' ), 'changelog does not claim production readiness' );

$pot = $root . '/languages/spx-express-woocommerce.pot';
$po  = $root . '/languages/spx-express-woocommerce-vi.po';
$mo  = $root . '/languages/spx-express-woocommerce-vi.mo';
spx_rc_check( is_file( $pot ), 'POT catalog exists' );
spx_rc_check( is_file( $po ), 'Vietnamese PO uses the WordPress vi locale' );
spx_rc_check( is_file( $mo ), 'Vietnamese MO uses the WordPress vi locale' );
if ( is_file( $po ) ) {
	$po_content = spx_rc_read( $po );
	spx_rc_check( false !== strpos( $po_content, '"Language: vi\\n"' ), 'Vietnamese PO declares locale vi' );
	spx_rc_check( false === strpos( $po_content, 'Language: vi_VN' ), 'legacy vi_VN locale is absent from the PO header' );
}
if ( is_file( $mo ) ) {
	$handle = fopen( $mo, 'rb' );
	$magic  = false !== $handle ? fread( $handle, 4 ) : '';
	if ( false !== $handle ) {
		fclose( $handle );
	}
	spx_rc_check( "\xDE\x12\x04\x95" === $magic || "\x95\x04\x12\xDE" === $magic, 'Vietnamese MO has a valid GNU gettext magic header' );
}

$guide_path = $root . '/USER-GUIDE-vi.md';
spx_rc_check( is_file( $guide_path ), 'Vietnamese user guide has the release filename' );
if ( is_file( $guide_path ) ) {
	$guide = spx_rc_read( $guide_path );
	foreach ( array( '## 1.', '## 24.', 'Create', 'scheduler', 'không phải định vị GPS trực tiếp', 'Webhook chưa', 'không tự hoàn tiền', 'Dynamic Production', 'UAT', '6G-B' ) as $required ) {
		spx_rc_check( false !== strpos( $guide, $required ), 'user guide contains required statement: ' . $required );
	}
}

spx_rc_check( false !== strpos( $fee, 'return new self( self::MODE_VERIFIED_VND, 1 );' ), 'Production fee contract uses multiplier 1' );
spx_rc_check( false !== strpos( $fee, 'return new self( self::MODE_EXPERIMENTAL_THOUSAND_VND, 1000 );' ), 'UAT fee contract keeps the internal multiplier 1000' );
spx_rc_check( false !== strpos( $router, 'SPX_Production_Gate::allows( $operation' ), 'Production operation router delegates to the enablement gate' );
$gate_src = spx_rc_read( dirname( __DIR__ ) . '/includes/production/class-spx-production-gate.php' );
spx_rc_check( false !== strpos( $gate_src, "'verification_required'" ), 'Production gate is fail-closed before verification' );
spx_rc_check( false !== strpos( $gate_src, "self::BOOTSTRAP_OPERATION" ), 'Production gate uses an explicit bootstrap policy for account_verify' );

if ( 0 === $failures ) {
	echo "OK test-spx-release-candidate\n";
	exit( 0 );
}
fwrite( STDERR, "{$failures} failure(s)\n" );
exit( 1 );
