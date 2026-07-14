<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Customer_Tracking {
	public static function init(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'style' ) );
	}
	public static function can_view( WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) { return true; }
		$user_id = get_current_user_id();
		if ( $user_id && (int) $order->get_user_id() === $user_id ) { return true; }
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		return '' !== $key && hash_equals( (string) $order->get_order_key(), (string) $key );
	}
	public static function render( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::can_view( $order ) ) { return; }
		$tracking = (string) $order->get_meta( '_spx_tracking_number', true );
		if ( ! SPX_Tracking_Service::is_real_tracking( $tracking ) ) { return; }
		$events = ( new SPX_Tracking_Event_Repository() )->get_by_order( $order->get_id(), 100 );
		$status = (string) $order->get_meta( '_spx_shipment_status', true );
		$label = (string) $order->get_meta( '_spx_shipment_status_label', true );
		echo '<section class="woocommerce-order-details spx-tracking"><h2>' . esc_html__( 'Theo dõi đơn hàng', 'spx-express-woocommerce' ) . '</h2>';
		echo '<div class="spx-tracking__summary"><strong>SPX Express</strong><span>' . esc_html( $tracking ) . '</span><span>' . esc_html( $label ?: __( 'Đang cập nhật', 'spx-express-woocommerce' ) ) . '</span></div>';
		self::progress( $status );
		$min = (string) $order->get_meta( '_spx_estimated_delivery_min', true ); $max = (string) $order->get_meta( '_spx_estimated_delivery_max', true );
		if ( $min || $max ) { echo '<p>' . esc_html__( 'Dự kiến giao:', 'spx-express-woocommerce' ) . ' ' . esc_html( trim( $min . ( $max && $max !== $min ? ' – ' . $max : '' ) ) ) . '</p>'; }
		if ( $events ) { echo '<ol class="spx-tracking__timeline">'; foreach ( $events as $event ) { echo '<li><time datetime="' . esc_attr( gmdate( 'c', (int) $event['event_timestamp'] ) ) . '">' . esc_html( wp_date( 'd/m/Y H:i', (int) $event['event_timestamp'] ) ) . '</time><strong>' . esc_html( $event['customer_label'] ) . '</strong><span>' . esc_html( $event['customer_message'] ) . '</span></li>'; } echo '</ol>'; }
		$link = (string) $order->get_meta( '_spx_tracking_link', true );
		if ( 'https' === strtolower( (string) wp_parse_url( $link, PHP_URL_SCHEME ) ) ) { echo '<p><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Theo dõi trên SPX Express', 'spx-express-woocommerce' ) . '</a></p>'; }
		echo '<p class="spx-tracking__updated">' . esc_html__( 'Cập nhật lần cuối:', 'spx-express-woocommerce' ) . ' ' . esc_html( (string) $order->get_meta( '_spx_last_sync_at', true ) ) . '</p></section>';
	}
	private static function progress( string $status ): void {
		$steps = array( 'created' => 1, 'pending_pickup' => 2, 'in_transit' => 3, 'out_for_delivery' => 4, 'delivered' => 5 ); $active = $steps[ $status ] ?? 0;
		$exception = in_array( $status, array( 'on_hold', 'pickup_failed', 'damaged', 'lost', 'returning', 'return_failed', 'returned', 'cancelled' ), true );
		echo '<div class="spx-tracking__progress' . ( $exception ? ' is-exception' : '' ) . '" aria-label="' . esc_attr__( 'Tiến trình giao hàng', 'spx-express-woocommerce' ) . '">';
		foreach ( array( 'Đã xác nhận', 'Chờ lấy hàng', 'Đang vận chuyển', 'Đang giao hàng', 'Đã giao' ) as $i => $name ) { echo '<span class="' . ( $active >= $i + 1 ? 'is-active' : '' ) . '">' . esc_html( $name ) . '</span>'; }
		echo '</div>';
	}
	public static function style(): void { if ( is_account_page() || is_order_received_page() ) { wp_enqueue_style( 'spx-tracking', plugins_url( 'assets/css/tracking.css', SPX_WC_FILE ), array(), SPX_WC_VERSION ); } }
}
