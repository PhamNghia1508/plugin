<?php
defined( 'ABSPATH' ) || exit;

/**
 * The per-product commission field on the product edit screen.
 *
 * Commission is a fixed VND amount per product (not a percentage), stored in
 * the `_affiliate_commission` post meta. A product with no value - or 0 - simply
 * earns nothing and is left out of the affiliate dashboard.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Product_Meta {

	const META_KEY = '_affiliate_commission';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );
	}

	/**
	 * Draw the field in the product's General tab.
	 */
	public static function render_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_KEY,
				'label'             => __( 'Hoa hồng cộng tác viên (₫)', 'wc-affiliate' ),
				'description'       => __( 'Số tiền cố định trả cho cộng tác viên khi đơn hàng chứa sản phẩm này hoàn thành. Để trống hoặc 0 nếu sản phẩm này không tính hoa hồng.', 'wc-affiliate' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1000',
				),
			)
		);
	}

	/**
	 * Persist the submitted value.
	 *
	 * Hooked to `woocommerce_admin_process_product_object`, which hands over the
	 * product object mid-save - WooCommerce writes the meta as part of its own
	 * save, so no separate $product->save() call is needed (and calling one here
	 * would trigger a redundant second write).
	 *
	 * Nonce verification is handled by WooCommerce before this hook fires.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_field( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce upstream.
		$raw = isset( $_POST[ self::META_KEY ] ) ? wp_unslash( $_POST[ self::META_KEY ] ) : '';

		$amount = '' === trim( (string) $raw ) ? 0 : (float) wc_format_decimal( $raw );
		$amount = max( 0, $amount );

		$product->update_meta_data( self::META_KEY, $amount );
	}

	/**
	 * Commission configured for a product (0 when it earns nothing).
	 *
	 * @param int $product_id Product ID.
	 * @return float
	 */
	public static function get_commission( int $product_id ): float {
		return (float) get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Published products that currently pay commission - the list the affiliate
	 * dashboard turns into referral links.
	 *
	 * @return WC_Product[]
	 */
	public static function get_commissionable_products(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => self::META_KEY,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$products = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return $products;
	}
}
