<?php
defined( 'ABSPATH' ) || exit;

/** Converts an explicitly classified raw SPX fee into a checkout candidate. */
final class SPX_Fee_Conversion_Contract {
	const VERSION = '6J-X-1';
	const MODE_UNCONFIRMED = 'unconfirmed';
	const MODE_EXPERIMENTAL_THOUSAND_VND = 'experimental_thousand_vnd';
	const MODE_VERIFIED_VND = 'verified_vnd';
	const MODE_VERIFIED_THOUSAND_VND = 'verified_thousand_vnd';
	const MIN_VND = 5000;
	const MAX_VND = 2000000;

	/** @var string */ private $mode;
	/** @var int */ private $multiplier;

	public function __construct( string $mode = self::MODE_UNCONFIRMED, int $multiplier = 1 ) {
		$this->mode = in_array( $mode, self::modes(), true ) ? $mode : self::MODE_UNCONFIRMED;
		$this->multiplier = $multiplier;
	}

	public static function modes(): array {
		return array( self::MODE_UNCONFIRMED, self::MODE_EXPERIMENTAL_THOUSAND_VND, self::MODE_VERIFIED_VND, self::MODE_VERIFIED_THOUSAND_VND );
	}

	/**
	 * UAT-only experimental fixture: SPX UAT returns mock fees (e.g. 21) and the
	 * ×1000 candidate is an internal experiment. Never Production evidence.
	 */
	public static function for_uat_experiment(): SPX_Fee_Conversion_Contract {
		return new self( self::MODE_EXPERIMENTAL_THOUSAND_VND, 1000 );
	}

	/**
	 * Production contract confirmed by SPX: estimated_shipping_fee is a full VND
	 * amount, so multiplier = 1 (raw 21000 → 21000). A tiny raw like 21 on
	 * Production is therefore rejected as suspicious rather than multiplied.
	 */
	public static function for_production_full_vnd(): SPX_Fee_Conversion_Contract {
		return new self( self::MODE_VERIFIED_VND, 1 );
	}

	public function is_production_full_vnd(): bool {
		return self::MODE_VERIFIED_VND === $this->mode && 1 === $this->multiplier;
	}

	public function is_experimental(): bool {
		return self::MODE_EXPERIMENTAL_THOUSAND_VND === $this->mode;
	}

	/**
	 * Choose the fee contract for a runtime context WITHOUT opening any gate.
	 * Production full-VND is selected ONLY when every production precondition is
	 * true (SPX env production, account verified, production operation allowed,
	 * production dynamic rate explicitly enabled) — none of which hold in Phase 6R.
	 * Otherwise the sandbox/local UAT experimental fixture is used.
	 *
	 * @param array<string,mixed> $gate
	 */
	public static function select( array $gate ): SPX_Fee_Conversion_Contract {
		$production_ready = 'production' === (string) ( $gate['spx_environment'] ?? '' )
			&& ! empty( $gate['account_verified'] )
			&& ! empty( $gate['production_operation_allowed'] )
			&& ! empty( $gate['production_dynamic_enabled'] );
		return $production_ready ? self::for_production_full_vnd() : self::for_uat_experiment();
	}

	public function convert( $raw ): array {
		if ( ! is_numeric( $raw ) || (float) $raw < 0 ) { return $this->failure( 'invalid_fee' ); }
		if ( self::MODE_UNCONFIRMED === $this->mode ) { return $this->failure( 'unit_unconfirmed' ); }
		$expected = in_array( $this->mode, array( self::MODE_EXPERIMENTAL_THOUSAND_VND, self::MODE_VERIFIED_THOUSAND_VND ), true ) ? 1000 : 1;
		if ( $expected !== $this->multiplier ) { return $this->failure( 'invalid_multiplier' ); }
		$candidate = (float) $raw * $this->multiplier;
		if ( $candidate < self::MIN_VND || $candidate > self::MAX_VND ) { return $this->failure( 'suspicious_fee' ); }
		return array(
			'success'       => true,
			'raw'           => 0 + $raw,
			'multiplier'    => $this->multiplier,
			'fee_unit'      => $this->mode,
			'currency'      => self::MODE_EXPERIMENTAL_THOUSAND_VND === $this->mode ? 'VND_candidate' : 'VND',
			'converted_vnd' => number_format( $candidate, 2, '.', '' ),
			'contract_version' => self::VERSION,
		);
	}

	public function safe_audit( $raw ): array {
		return array(
			'raw_estimated_shipping_fee' => is_numeric( $raw ) ? (string) ( 0 + $raw ) : '',
			'multiplier' => $this->multiplier,
			'unit_mode' => $this->mode,
			'contract_version' => self::VERSION,
		);
	}

	private function failure( string $code ): array {
		return array( 'success' => false, 'error_code' => $code, 'fee_unit' => $this->mode, 'multiplier' => $this->multiplier, 'contract_version' => self::VERSION );
	}
}

