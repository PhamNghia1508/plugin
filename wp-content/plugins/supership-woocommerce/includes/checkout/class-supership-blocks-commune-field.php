<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Blocks Checkout Commune Field
 *
 * The block-based Checkout doesn't render the classic-checkout commune
 * dropdown (that cascade is wired through woocommerce_billing_fields +
 * country-select.js, both classic-only). Without a Phường/Xã value the
 * SuperShip order-creation API rejects the shipment ("Thiếu Phường/Xã của
 * người nhận"), forcing the admin to fill it in manually per order.
 *
 * This registers Phường/Xã as an Additional Checkout Field (WooCommerce
 * 8.9+ Blocks extensibility API) in the address section, then copies the
 * submitted value onto the order's canonical `_shipping_commune` meta that
 * SuperShip_Order_Actions and the shipment services already read.
 *
 * Plain text (not a cascading dropdown): the Additional Checkout Fields API
 * only supports static select options, so a canonical-name dropdown per
 * district isn't possible here. Free text may not match SuperShip's
 * canonical commune names exactly - the admin metabox picker (see
 * SuperShip_Order_Actions::render_missing_commune_field) remains the
 * fallback for correcting it. Classic checkout stays the recommended mode.
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Blocks_Commune_Field {

	const FIELD_ID = 'supership/commune';

	public static function init(): void {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_field' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'copy_to_shipping_commune' ) );
	}

	/**
	 * Register the commune field with the Blocks checkout (no-op on
	 * WooCommerce versions without the Additional Checkout Fields API).
	 */
	public static function register_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD_ID,
				'label'    => __( 'Phường/Xã', 'supership-woocommerce' ),
				'location' => 'address',
				'type'     => 'text',
				'required' => true,
				'attributes' => array(
					'placeholder' => __( 'VD: Phường Võ Thị Sáu', 'supership-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Copy the additional-field value to `_shipping_commune`, the canonical
	 * meta the SuperShip services read. Blocks stores address-location
	 * additional fields per address type; prefer shipping, fall back to
	 * billing (billing == shipping in the "use same address" default flow).
	 *
	 * @param WC_Order $order Order created through the Store API checkout.
	 */
	public static function copy_to_shipping_commune( WC_Order $order ): void {
		if ( '' !== (string) $order->get_meta( '_shipping_commune' ) ) {
			return;
		}

		foreach ( array( '_wc_shipping/supership/commune', '_wc_billing/supership/commune' ) as $meta_key ) {
			$value = (string) $order->get_meta( $meta_key );
			if ( '' !== $value ) {
				$order->update_meta_data( '_shipping_commune', sanitize_text_field( $value ) );
				$order->save();
				return;
			}
		}
	}
}
