<?php
declare( strict_types=1 );

/** Runs every standalone plugin test in an isolated PHP process. */

$root        = dirname( __DIR__ );
$files       = glob( __DIR__ . '/test-*.php' );
$passed      = 0;
$failed      = 0;
$linted      = 0;
$lint_failed = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $candidate ) {
	if ( ! $candidate->isFile() || 'php' !== strtolower( $candidate->getExtension() ) ) {
		continue;
	}
	$output = array();
	$code   = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $candidate->getPathname() ) . ' 2>&1', $output, $code );
	++$linted;
	if ( 0 !== $code ) {
		++$lint_failed;
		echo 'LINT_FAIL ' . str_replace( $root . DIRECTORY_SEPARATOR, '', $candidate->getPathname() ) . PHP_EOL;
		echo implode( PHP_EOL, $output ) . PHP_EOL;
	}
}
echo "LINT_PASS=" . ( $linted - $lint_failed ) . " LINT_FAIL={$lint_failed}" . PHP_EOL;

sort( $files );
foreach ( $files as $file ) {
	$output = array();
	$code   = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
	if ( 0 === $code ) {
		++$passed;
		echo 'PASS ' . basename( $file ) . PHP_EOL;
		continue;
	}
	++$failed;
	echo 'FAIL ' . basename( $file ) . PHP_EOL;
	echo implode( PHP_EOL, $output ) . PHP_EOL;
}

echo "SUITE_PASS={$passed} SUITE_FAIL={$failed}" . PHP_EOL;
exit( 0 === $failed && 0 === $lint_failed ? 0 : 1 );
