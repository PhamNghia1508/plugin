<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip - declutter the order edit screen for a COD/parcel shop.
 *
 * A Vietnamese SuperShip merchant processes orders in one loop: check the
 * address -> create the shipment -> print the label. WooCommerce's default
 * edit screen surrounds that loop with panels that never matter in this flow
 * (downloadable-file permissions, raw custom fields, marketing attribution,
 * customer analytics) and dumps raw shipping meta (service_code, fee...) into
 * the order items table. Everything removed here is hidden, not deleted -
 * the underlying data stays untouched, and any other plugin reading it keeps
 * working.
 *
 * Metabox IDs verified against the installed WooCommerce source
 * (src/Internal/Admin/Orders/Edit.php for HPOS, class-wc-admin-meta-boxes.php
 * for the legacy posts screen).
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Admin_Order_Cleanup {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Priority 60: WooCommerce registers its boxes on add_meta_boxes at
		// default/30 (attribution via its controller) - we must run after
		// every one of them or remove_meta_box() is a no-op.
		add_action( 'add_meta_boxes', array( __CLASS__, 'remove_noise_metaboxes' ), 60 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_raw_shipping_itemmeta' ) );

		// "Nguồn gốc" (marketing attribution) column in the Orders list -
		// same analytics feature as the metabox removed below, and its values
		// surface as unhelpful noise ("Không hiểu"/"Unknown") for COD shops.
		// Priority 40: after WooCommerce adds it, after our own Vận đơn column (20).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'remove_attribution_column' ), 40 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'remove_attribution_column' ), 40 );
	}

	/**
	 * Drop the order-attribution ("Origin") column from the Orders list.
	 *
	 * @param array $columns List-table columns.
	 * @return array
	 */
	public static function remove_attribution_column( array $columns ): array {
		unset( $columns['origin'] );
		return $columns;
	}

	/**
	 * Remove panels that don't serve the parcel-fulfillment flow.
	 *
	 * Covers both order storage modes: the HPOS screen id
	 * (woocommerce_page_wc-orders) and the legacy shop_order post screen.
	 */
	public static function remove_noise_metaboxes(): void {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			// Downloadable product permissions - meaningless for physical parcels.
			remove_meta_box( 'woocommerce-order-downloads', $screen, 'normal' );

			// Raw meta editor ("Trường tùy chỉnh") - shows internal keys like
			// is_vat_exempt; editing them by hand only breaks things.
			remove_meta_box( 'order_custom', $screen, 'normal' ); // HPOS.
			remove_meta_box( 'postcustom', $screen, 'normal' );   // Legacy posts screen.

			// Marketing attribution + customer analytics side panels.
			remove_meta_box( 'woocommerce-order-source-data', $screen, 'side' );
			remove_meta_box( 'woocommerce-customer-history', $screen, 'side' );
		}
	}

	/**
	 * Hide SuperShip's raw shipping-rate meta from the order items table.
	 *
	 * These keys (written by the shipping method's meta_data when a live rate
	 * is chosen) are plumbing, not information: the service name already
	 * appears as the shipping line's label and the fee as its amount.
	 *
	 * @param array $hidden Meta keys WooCommerce already hides.
	 * @return array
	 */
	public static function hide_raw_shipping_itemmeta( array $hidden ): array {
		return array_merge( $hidden, array( 'service_code', 'service_name', 'fee', 'insurance' ) );
	}
}
