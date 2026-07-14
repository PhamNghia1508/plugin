<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detects which checkout flow is active per request.
 *
 * Modes:
 *   - classic:     WooCommerce shortcode-based checkout.
 *   - blocks:      WooCommerce Checkout Blocks (Store API).
 *   - unsupported: Unknown checkout — SPX must not render fields.
 *
 * Detection inspects the checkout page content and WooCommerce runtime,
 * not just the URL. One adapter per request — never both.
 */
final class SPX_Checkout_Mode_Detector {
	const MODE_CLASSIC     = 'classic';
	const MODE_BLOCKS      = 'blocks';
	const MODE_UNSUPPORTED = 'unsupported';

	/** @var string|null Cached result for the current request. */
	private static $cached_mode = null;

	/** @var bool */
	private static $detection_ran = false;

	/**
	 * Detect the active checkout mode.
	 *
	 * @return string One of MODE_CLASSIC, MODE_BLOCKS, MODE_UNSUPPORTED.
	 */
	public static function detect(): string {
		if ( self::$detection_ran ) {
			return self::$cached_mode ?: self::MODE_UNSUPPORTED;
		}

		self::$detection_ran = true;
		self::$cached_mode   = self::run_detection();

		return self::$cached_mode;
	}

	public static function is_classic(): bool {
		return self::MODE_CLASSIC === self::detect();
	}

	public static function is_blocks(): bool {
		return self::MODE_BLOCKS === self::detect();
	}

	public static function is_unsupported(): bool {
		return self::MODE_UNSUPPORTED === self::detect();
	}

	/**
	 * Reset the cached mode (useful for testing).
	 */
	public static function reset(): void {
		self::$cached_mode   = null;
		self::$detection_ran = false;
	}

	/**
	 * Force a specific mode (for testing or manual override).
	 *
	 * @param string $mode
	 */
	public static function force( string $mode ): void {
		if ( in_array( $mode, array( self::MODE_CLASSIC, self::MODE_BLOCKS, self::MODE_UNSUPPORTED ), true ) ) {
			self::$cached_mode   = $mode;
			self::$detection_ran = true;
		}
	}

	/**
	 * Run the actual detection logic.
	 *
	 * @return string
	 */
	private static function run_detection(): string {
		// 1. Check if WooCommerce is available.
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return self::MODE_UNSUPPORTED;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( $checkout_page_id <= 0 ) {
			return self::MODE_UNSUPPORTED;
		}

		// 2. Get the checkout page content.
		$post = function_exists( 'get_post' ) ? get_post( $checkout_page_id ) : null;
		if ( ! $post || ! isset( $post->post_content ) ) {
			return self::MODE_UNSUPPORTED;
		}

		$content = (string) $post->post_content;

		// 3. Check for Checkout Blocks.
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
			return self::MODE_BLOCKS;
		}

		// Fallback: look for the block comment pattern in content.
		if ( false !== strpos( $content, '<!-- wp:woocommerce/checkout' ) ) {
			return self::MODE_BLOCKS;
		}

		// 4. Check for Classic Checkout shortcode.
		if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'woocommerce_checkout' ) ) {
			return self::MODE_CLASSIC;
		}

		// Fallback: raw shortcode detection.
		if ( false !== strpos( $content, '[woocommerce_checkout' ) ) {
			return self::MODE_CLASSIC;
		}

		// 5. Check if WC_Shortcodes class has registered the checkout shortcode.
		if ( class_exists( 'WC_Shortcodes' ) && shortcode_exists( 'woocommerce_checkout' ) ) {
			// The checkout page exists and WC shortcodes are registered,
			// but the page content doesn't contain the shortcode or block.
			// This could be a custom checkout using Classic hooks.
			// Assume classic if WC classic checkout hooks are available.
			if ( has_action( 'woocommerce_checkout_order_review' ) ) {
				return self::MODE_CLASSIC;
			}
		}

		return self::MODE_UNSUPPORTED;
	}

	/**
	 * Admin notice for unsupported checkout mode.
	 */
	public static function admin_compatibility_notice(): void {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		if ( ! self::is_unsupported() ) {
			return;
		}

		add_action( 'admin_notices', static function () {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__(
				'SPX Express: Trang Checkout hiện tại không được nhận dạng (không phải Classic hoặc Blocks). Các trường địa chỉ SPX sẽ không được hiển thị. Vui lòng sử dụng WooCommerce Classic Checkout hoặc Checkout Blocks.',
				'spx-express-woocommerce'
			);
			echo '</p></div>';
		} );
	}
}
