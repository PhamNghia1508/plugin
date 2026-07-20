<?php
defined( 'ABSPATH' ) || exit;

/** Raised when a payload cannot be encoded; never carries the app-secret. */
final class SPX_Signer_Exception extends RuntimeException {}

/**
 * Implements the official SPX check-sign scheme:
 *   message    = "<app-id>_<timestamp>_<random-num>_<payload>"
 *   check-sign = lowercase_hex( HMAC_SHA256( key = app-secret, message ) )
 * The exact JSON string that is signed is the exact string that must be sent.
 */
final class SPX_Request_Signer {
	/**
	 * Encode + sign a JSON POST body.
	 *
	 * @return array{body:string,headers:array<string,string>}
	 * @throws SPX_Signer_Exception on JSON encode failure.
	 */
	public function sign( string $app_id, string $app_secret, array $payload ): array {
		$body = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $body ) {
			throw new SPX_Signer_Exception( 'Failed to encode the SPX request payload as JSON.' );
		}
		$timestamp  = $this->timestamp();
		$random_num = $this->random_num();
		$check_sign = $this->check_sign( $app_id, $app_secret, $timestamp, $random_num, $body );

		return array(
			'body'    => $body,
			'headers' => array(
				'app-id'       => $app_id,
				'check-sign'   => $check_sign,
				'timestamp'    => (string) $timestamp,
				'random-num'   => (string) $random_num,
				'Content-Type' => 'application/json',
			),
		);
	}

	/** Deterministic core, verifiable against the official test vector. */
	public function check_sign( string $app_id, string $app_secret, int $timestamp, int $random_num, string $body ): string {
		$message = sprintf( '%d_%d_%d_%s', (int) $app_id, $timestamp, $random_num, $body );
		return hash_hmac( 'sha256', $message, $app_secret );
	}

	protected function timestamp(): int {
		return time();
	}

	protected function random_num(): int {
		return random_int( 1, PHP_INT_MAX );
	}
}
