<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Webhook_Verifier {
	public function is_available(): bool { return false; }
	public function verify( string $raw_body, array $headers = array() ): array {
		return array( 'verified' => false, 'reason' => 'official_signature_documentation_missing' );
	}
}
