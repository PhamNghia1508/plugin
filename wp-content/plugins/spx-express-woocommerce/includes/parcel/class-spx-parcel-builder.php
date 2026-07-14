<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SPX_Parcel_Validation_Result' ) ) {
	require_once __DIR__ . '/class-spx-parcel-validation-result.php';
}

/**
 * Canonical, single-source parcel model shared by Rate and Create so both use ONE
 * deterministic formula. Pure with respect to WordPress: `from_lines()` operates on
 * already-extracted primitives and is fully unit-testable; `from_package()` /
 * `from_order()` (WC adapters, added during integration) simply extract WooCommerce
 * product data and delegate here.
 *
 * Deterministic packaging approximation (NOT 3D bin packing / packing optimization):
 *   weight_kg = Σ(item_weight_kg × qty)
 *   length_cm = max(item_length_cm)
 *   width_cm  = max(item_width_cm)
 *   height_cm = Σ(item_height_cm × qty)
 *
 * Semantic length/width/height are preserved (SPX does not require sorted edges).
 * Missing data is never coerced to 0 — it is resolved by the configured policy.
 */
final class SPX_Parcel_Builder {
	const POLICY_VERSION = '6Q-1';

	const POLICY_FAIL_CLOSED  = 'fail_closed';
	const POLICY_SHOP_DEFAULT = 'shop_default';

	/** SPX Vietnam limits. Rate caps weight at 15 kg, Create at 17 kg. */
	const MAX_DIM_CM     = 60.0;
	const MAX_DIM_SUM_CM = 180.0;

	/**
	 * Convert a weight to kilograms using the same factors WooCommerce uses.
	 * Intentionally self-contained (no wc_get_weight call) so the canonical parcel
	 * result is deterministic and independent of differing wc_get_weight stubs.
	 */
	private static function weight_to_kg( float $value, string $unit ): float {
		$factors = array( 'kg' => 1.0, 'g' => 0.001, 'lbs' => 0.45359237, 'oz' => 0.028349523125 );
		$factor  = $factors[ strtolower( $unit ) ] ?? 1.0;
		return max( 0.0, $value ) * $factor;
	}

	/** Length unit → cm conversion (decimal-safe; never integer-cast before converting). */
	private static function dimension_to_cm( float $value, string $unit ): float {
		$factors = array( 'cm' => 1.0, 'mm' => 0.1, 'm' => 100.0, 'in' => 2.54, 'yd' => 91.44 );
		$factor  = $factors[ strtolower( $unit ) ] ?? 1.0;
		return max( 0.0, $value ) * $factor;
	}

	private static function normalize_policy( string $policy ): string {
		return self::POLICY_SHOP_DEFAULT === $policy ? self::POLICY_SHOP_DEFAULT : self::POLICY_FAIL_CLOSED;
	}

	const OPTION_POLICY   = 'spx_parcel_missing_policy';
	const OPTION_DEFAULTS = 'spx_parcel_defaults';

	/**
	 * Validate raw default-parcel settings input into a canonical, clamped shape.
	 * Never returns negatives; caps weight at 17000 g and each dimension at 60 cm.
	 *
	 * @param array<string,mixed> $input
	 * @return array{defaults:array<string,float>,policy:string}
	 */
	public static function sanitize_defaults_input( array $input ): array {
		$clamp = static function ( $value, float $max ): float {
			$n = is_numeric( $value ) ? (float) $value : 0.0;
			return max( 0.0, min( $max, $n ) );
		};
		return array(
			'defaults' => array(
				'weight_grams' => $clamp( $input['parcel_weight_grams'] ?? 0, 17000.0 ),
				'length_cm'    => $clamp( $input['parcel_length_cm'] ?? 0, self::MAX_DIM_CM ),
				'width_cm'     => $clamp( $input['parcel_width_cm'] ?? 0, self::MAX_DIM_CM ),
				'height_cm'    => $clamp( $input['parcel_height_cm'] ?? 0, self::MAX_DIM_CM ),
			),
			'policy'   => self::normalize_policy( (string) ( $input['parcel_policy'] ?? '' ) ),
		);
	}

	/** Shop-configured missing-data policy; defaults to fail_closed (safest for production). */
	public static function configured_policy(): string {
		$value = function_exists( 'get_option' ) ? (string) get_option( self::OPTION_POLICY, self::POLICY_FAIL_CLOSED ) : self::POLICY_FAIL_CLOSED;
		return self::normalize_policy( $value );
	}

	/** Shop-configured default parcel dimensions/weight, normalized to canonical kg/cm option keys. */
	public static function configured_defaults(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_DEFAULTS, array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'default_weight_kg' => max( 0.0, (float) ( $stored['weight_grams'] ?? 0 ) ) / 1000,
			'default_length_cm' => max( 0.0, (float) ( $stored['length_cm'] ?? 0 ) ),
			'default_width_cm'  => max( 0.0, (float) ( $stored['width_cm'] ?? 0 ) ),
			'default_height_cm' => max( 0.0, (float) ( $stored['height_cm'] ?? 0 ) ),
		);
	}

	/**
	 * Build a canonical parcel from resolved line primitives.
	 *
	 * Each line:
	 *   weight, weight_unit, length, width, height, dimension_unit, quantity, virtual(bool)
	 *
	 * Options:
	 *   policy            'fail_closed' | 'shop_default'
	 *   max_weight_kg     15 (rate) | 17 (create)
	 *   default_weight_kg float (0 = unset)
	 *   default_length_cm float, default_width_cm float, default_height_cm float
	 *   require_dimensions bool (default true — stricter than SPX's optional dims, by shop choice)
	 *
	 * @param array<int,array<string,mixed>> $lines
	 * @param array<string,mixed>            $options
	 */
	public static function from_lines( array $lines, array $options = array() ): SPX_Parcel_Validation_Result {
		$policy        = self::normalize_policy( (string) ( $options['policy'] ?? self::POLICY_FAIL_CLOSED ) );
		$max_weight    = (float) ( $options['max_weight_kg'] ?? 17.0 );
		$def_weight_kg = max( 0.0, (float) ( $options['default_weight_kg'] ?? 0.0 ) );
		$def_l         = max( 0.0, (float) ( $options['default_length_cm'] ?? 0.0 ) );
		$def_w         = max( 0.0, (float) ( $options['default_width_cm'] ?? 0.0 ) );
		$def_h         = max( 0.0, (float) ( $options['default_height_cm'] ?? 0.0 ) );
		$require_dims  = ! array_key_exists( 'require_dimensions', $options ) || (bool) $options['require_dimensions'];
		$has_shop_dims = $def_l > 0 && $def_w > 0 && $def_h > 0;

		$errors   = array();
		$warnings = array();

		$weight_kg  = 0.0;
		$max_length = 0.0;
		$max_width  = 0.0;
		$sum_height = 0.0;
		$item_count = 0;
		$used_product = false;
		$used_default = false;
		$dims_missing = false;

		foreach ( $lines as $line ) {
			if ( ! empty( $line['virtual'] ) ) { continue; }
			$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$item_count += $quantity;

			// --- Weight ---------------------------------------------------
			$raw_weight = isset( $line['weight'] ) && is_numeric( $line['weight'] ) ? (float) $line['weight'] : -1.0;
			$line_kg    = $raw_weight >= 0 ? self::weight_to_kg( $raw_weight, (string) ( $line['weight_unit'] ?? 'kg' ) ) : 0.0;
			if ( $raw_weight < 0 || $line_kg <= 0 ) {
				if ( self::POLICY_SHOP_DEFAULT === $policy && $def_weight_kg > 0 ) {
					$line_kg      = $def_weight_kg;
					$used_default = true;
					$warnings[]   = 'weight_from_default';
				} elseif ( self::POLICY_SHOP_DEFAULT === $policy ) {
					self::add_error( $errors, 'default_not_configured', __( 'Chưa cấu hình kiện hàng mặc định.', 'spx-express-woocommerce' ) );
					continue;
				} else {
					self::add_error( $errors, 'missing_weight', __( 'Sản phẩm chưa có cân nặng.', 'spx-express-woocommerce' ) );
					continue;
				}
			} else {
				$used_product = true;
			}
			$weight_kg += $line_kg * $quantity;

			// --- Dimensions -----------------------------------------------
			$unit = (string) ( $line['dimension_unit'] ?? 'cm' );
			$l    = isset( $line['length'] ) && is_numeric( $line['length'] ) ? self::dimension_to_cm( (float) $line['length'], $unit ) : 0.0;
			$w    = isset( $line['width'] ) && is_numeric( $line['width'] ) ? self::dimension_to_cm( (float) $line['width'], $unit ) : 0.0;
			$h    = isset( $line['height'] ) && is_numeric( $line['height'] ) ? self::dimension_to_cm( (float) $line['height'], $unit ) : 0.0;
			if ( $l <= 0 || $w <= 0 || $h <= 0 ) {
				if ( $has_shop_dims ) {
					$l = $def_l; $w = $def_w; $h = $def_h;
					$used_default = true;
					$warnings[]   = 'dimensions_from_default';
				} else {
					$dims_missing = true;
					continue; // cannot fold missing dims into the deterministic policy
				}
			} else {
				$used_product = true;
			}
			$max_length  = max( $max_length, $l );
			$max_width   = max( $max_width, $w );
			$sum_height += $h * $quantity;
		}

		// --- Structural + policy checks -----------------------------------
		if ( 0 === $item_count ) {
			self::add_error( $errors, 'no_shippable_items', __( 'Đơn hàng không có sản phẩm cần giao.', 'spx-express-woocommerce' ) );
		}
		if ( $dims_missing && $require_dims ) {
			if ( self::POLICY_SHOP_DEFAULT === $policy && ! $has_shop_dims ) {
				self::add_error( $errors, 'default_not_configured', __( 'Chưa cấu hình kiện hàng mặc định.', 'spx-express-woocommerce' ) );
			} else {
				self::add_error( $errors, 'missing_dimensions', __( 'Sản phẩm chưa có đầy đủ kích thước.', 'spx-express-woocommerce' ) );
			}
		} elseif ( $dims_missing ) {
			$warnings[] = 'dimensions_omitted';
		}

		$weight_kg = round( $weight_kg, 3 );

		// --- Limit checks (only meaningful when we have positive values) ---
		if ( $weight_kg > $max_weight ) {
			self::add_error( $errors, 'weight_over_limit', __( 'Kiện hàng vượt giới hạn cân nặng SPX.', 'spx-express-woocommerce' ) );
		}
		$have_dims = $max_length > 0 && $max_width > 0 && $sum_height > 0;
		if ( $have_dims ) {
			if ( $max_length > self::MAX_DIM_CM || $max_width > self::MAX_DIM_CM || $sum_height > self::MAX_DIM_CM ) {
				self::add_error( $errors, 'dimension_over_limit', __( 'Một chiều kiện hàng vượt giới hạn SPX.', 'spx-express-woocommerce' ) );
			}
			if ( ( $max_length + $max_width + $sum_height ) > self::MAX_DIM_SUM_CM ) {
				self::add_error( $errors, 'dimension_sum_over_limit', __( 'Tổng kích thước kiện hàng vượt giới hạn SPX.', 'spx-express-woocommerce' ) );
			}
		}

		$source = self::resolve_source( $item_count, $used_product, $used_default );
		$valid  = empty( $errors ) && $item_count > 0 && $weight_kg > 0;

		return new SPX_Parcel_Validation_Result(
			$valid,
			$weight_kg,
			round( $max_length, 2 ),
			round( $max_width, 2 ),
			round( $sum_height, 2 ),
			$item_count,
			$source,
			$warnings,
			$errors,
			self::POLICY_VERSION
		);
	}

	private static function resolve_source( int $item_count, bool $used_product, bool $used_default ): string {
		if ( 0 === $item_count ) { return 'none'; }
		if ( $used_product && $used_default ) { return 'mixed'; }
		if ( $used_default ) { return 'shop_default'; }
		return 'product';
	}

	/**
	 * @param array<int,array{code:string,message:string}> $errors
	 */
	private static function add_error( array &$errors, string $code, string $message ): void {
		foreach ( $errors as $existing ) {
			if ( ( $existing['code'] ?? '' ) === $code ) { return; }
		}
		$errors[] = array( 'code' => $code, 'message' => $message );
	}
}
