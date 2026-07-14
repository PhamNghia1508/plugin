<?php
defined( 'ABSPATH' ) || exit;

/** Seam so higher-level services can be unit-tested with a fake transport. */
interface SPX_Http_Client_Interface {
	public function request( string $path, array $payload ): SPX_API_Response;
}
