<?php
defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of building a canonical SPX parcel from cart/order lines.
 *
 * Pure value object: no WordPress, no network. Error messages are already
 * Vietnamese and safe to show to admins/customers; error codes are internal
 * slugs kept only for logic/logging and are never required for display.
 */
final class SPX_Parcel_Validation_Result {
	/** @var bool */ private $valid;
	/** @var float */ private $weight_kg;
	/** @var float */ private $length_cm;
	/** @var float */ private $width_cm;
	/** @var float */ private $height_cm;
	/** @var int */ private $item_count;
	/** @var string */ private $source;
	/** @var string[] */ private $warnings;
	/** @var array<int,array{code:string,message:string}> */ private $errors;
	/** @var string */ private $policy_version;

	/**
	 * @param string[]                                   $warnings
	 * @param array<int,array{code:string,message:string}> $errors
	 */
	public function __construct(
		bool $valid,
		float $weight_kg,
		float $length_cm,
		float $width_cm,
		float $height_cm,
		int $item_count,
		string $source,
		array $warnings,
		array $errors,
		string $policy_version
	) {
		$this->valid          = $valid;
		$this->weight_kg      = $weight_kg;
		$this->length_cm      = $length_cm;
		$this->width_cm       = $width_cm;
		$this->height_cm      = $height_cm;
		$this->item_count     = $item_count;
		$this->source         = $source;
		$this->warnings       = array_values( array_unique( $warnings ) );
		$this->errors         = $errors;
		$this->policy_version = $policy_version;
	}

	public function is_valid(): bool { return $this->valid; }
	public function weight_kg(): float { return $this->weight_kg; }
	public function length_cm(): float { return $this->length_cm; }
	public function width_cm(): float { return $this->width_cm; }
	public function height_cm(): float { return $this->height_cm; }
	public function item_count(): int { return $this->item_count; }
	public function source(): string { return $this->source; }
	/** @return string[] */
	public function warnings(): array { return $this->warnings; }
	/** @return array<int,array{code:string,message:string}> */
	public function errors(): array { return $this->errors; }
	public function policy_version(): string { return $this->policy_version; }

	/** True only when every physical dimension is a positive number. */
	public function has_dimensions(): bool {
		return $this->length_cm > 0 && $this->width_cm > 0 && $this->height_cm > 0;
	}

	/** First Vietnamese error message, or empty string when valid. */
	public function first_error_message(): string {
		return isset( $this->errors[0]['message'] ) ? (string) $this->errors[0]['message'] : '';
	}

	/** @return string[] Internal error slugs (for logs, never for display). */
	public function error_codes(): array {
		return array_map(
			static function ( $error ) { return (string) ( $error['code'] ?? '' ); },
			$this->errors
		);
	}

	/** @return string[] Vietnamese messages, deduplicated, safe to render. */
	public function error_messages(): array {
		$messages = array_map(
			static function ( $error ) { return (string) ( $error['message'] ?? '' ); },
			$this->errors
		);
		return array_values( array_unique( array_filter( $messages ) ) );
	}

	/**
	 * Parts used to build a cache key. Includes the policy version so a change to
	 * packaging rules or shop defaults never serves a stale checkout quote.
	 *
	 * @return array<string,scalar>
	 */
	public function cache_parts(): array {
		return array(
			'weight_kg'      => $this->weight_kg,
			'length_cm'      => $this->length_cm,
			'width_cm'       => $this->width_cm,
			'height_cm'      => $this->height_cm,
			'item_count'     => $this->item_count,
			'source'         => $this->source,
			'policy_version' => $this->policy_version,
		);
	}

	/**
	 * Snapshot suitable for order meta audit (no product data, no PII).
	 *
	 * @return array<string,scalar>
	 */
	public function to_snapshot(): array {
		return array(
			'_spx_parcel_weight_kg'      => $this->weight_kg,
			'_spx_parcel_length_cm'      => $this->length_cm,
			'_spx_parcel_width_cm'       => $this->width_cm,
			'_spx_parcel_height_cm'      => $this->height_cm,
			'_spx_parcel_source'         => $this->source,
			'_spx_parcel_policy_version' => $this->policy_version,
		);
	}
}
