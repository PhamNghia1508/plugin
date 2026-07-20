<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Order_Actions {
	public static function init() {
		add_action( 'woocommerce_order_action_spx_create_shipment', array( __CLASS__, 'create_shipment' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_style' ) );
	}

	public static function add_action( array $actions ): array {
		if ( current_user_can( 'edit_shop_orders' ) ) {
			$actions['spx_create_shipment'] = __( 'Tạo vận đơn SPX', 'spx-express-woocommerce' );
		}
		return $actions;
	}

	public static function create_shipment( $order ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) { return; }
		$existing = $order->get_meta( '_spx_tracking_number', true );
		if ( $existing ) {
			$order->add_order_note( __( 'SPX shipment was not created because this order already has a tracking number.', 'spx-express-woocommerce' ) );
			SPX_Logger::log( 'warning', 'shipment_duplicate_skipped', $order->get_id(), (string) $existing );
			return;
		}

		$instance_id = self::get_instance_id( $order );
		$settings    = SPX_Settings::get_instance_settings( $instance_id );
		$shipment    = SPX_Order_Mapper::build( $order, $settings );
		$errors      = self::validate_shipment( $shipment );
		if ( $errors ) {
			$message = implode( ' ', $errors );
			$order->add_order_note( sprintf( __( 'SPX shipment could not be created: %s', 'spx-express-woocommerce' ), $message ) );
			SPX_Logger::log( 'error', 'shipment_validation_failed', $order->get_id(), '', $message );
			return;
		}

		$result = SPX_Settings::provider( $instance_id )->create_shipment( $shipment );
		if ( empty( $result['success'] ) ) {
			$message = sanitize_text_field( $result['message'] ?? __( 'Unknown provider error.', 'spx-express-woocommerce' ) );
			$order->add_order_note( sprintf( __( 'SPX shipment could not be created: %s', 'spx-express-woocommerce' ), $message ) );
			SPX_Logger::log( 'error', 'shipment_create_failed', $order->get_id(), '', $message );
			return;
		}

		$tracking = sanitize_text_field( $result['tracking_number'] ?? '' );
		if ( ! preg_match( '/^SPXMOCK-\d{8}-[A-Z0-9]{6}$/', $tracking ) && 'mock' === ( $result['provider'] ?? '' ) ) {
			$message = __( 'Nhà vận chuyển trả về mã vận đơn không hợp lệ.', 'spx-express-woocommerce' );
			$order->add_order_note( $message );
			SPX_Logger::log( 'error', 'shipment_invalid_tracking', $order->get_id(), '', $message );
			return;
		}
		if ( '' === $tracking ) {
			$message = __( 'Nhà vận chuyển không trả về mã vận đơn.', 'spx-express-woocommerce' );
			$order->add_order_note( $message );
			SPX_Logger::log( 'error', 'shipment_missing_tracking', $order->get_id(), '', $message );
			return;
		}
		$created  = sanitize_text_field( $result['created_at'] ?? current_time( 'mysql', true ) );
		$order->update_meta_data( '_spx_tracking_number', $tracking );
		$order->update_meta_data( '_spx_shipment_status', sanitize_key( $result['status'] ?? 'created' ) );
		$order->update_meta_data( '_spx_shipment_created_at', $created );
		$order->update_meta_data( '_spx_last_sync_at', $created );
		$order->update_meta_data( '_spx_raw_response', array( 'provider' => sanitize_key( $result['provider'] ?? 'unknown' ), 'success' => true ) );
		$order->add_order_note( sprintf( __( 'SPX shipment created. Tracking number: %s', 'spx-express-woocommerce' ), $tracking ) );
		$order->save();
		SPX_Logger::log( 'info', 'shipment_created', $order->get_id(), $tracking );
	}

	private static function validate_shipment( array $shipment ): array {
		$errors = array();
		if ( true !== ( $shipment['ready_for_shipment'] ?? false ) ) {
			$reason = sanitize_key( (string) ( $shipment['payment_block_reason'] ?? 'payment_not_confirmed' ) );
			$messages = array(
				'payment_method_missing' => __( 'Đơn hàng chưa có phương thức thanh toán.', 'spx-express-woocommerce' ),
				'payment_not_confirmed' => __( 'Thanh toán trực tuyến chưa được xác nhận.', 'spx-express-woocommerce' ),
				'order_status_blocked' => __( 'Trạng thái đơn hàng không cho phép tạo vận đơn mới.', 'spx-express-woocommerce' ),
				'invalid_order_total' => __( 'Tổng tiền đơn hàng không hợp lệ.', 'spx-express-woocommerce' ),
			);
			$errors[] = $messages[ $reason ] ?? $messages['payment_not_confirmed'];
		}
		if ( empty( $shipment['recipient']['address'] ) ) { $errors[] = __( 'Recipient address is missing.', 'spx-express-woocommerce' ); }
		if ( empty( $shipment['recipient']['phone'] ) ) { $errors[] = __( 'Recipient phone is missing.', 'spx-express-woocommerce' ); }
		if ( empty( $shipment['items'] ) ) { $errors[] = __( 'Order products are missing.', 'spx-express-woocommerce' ); }
		if ( empty( $shipment['weight_grams'] ) || $shipment['weight_grams'] < 1 ) { $errors[] = __( 'Shipment weight is invalid.', 'spx-express-woocommerce' ); }
		return $errors;
	}

	private static function get_instance_id( WC_Order $order ): int {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 'spx_express' === $item->get_method_id() ) { return absint( $item->get_instance_id() ); }
		}
		return 0;
	}

	public static function render_admin_tracking( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) { return; }
		$tracking = $order->get_meta( '_spx_tracking_number', true );
		if ( ! $tracking ) { return; }
		echo '<div class="spx-shipment-details"><h3>' . esc_html__( 'Vận đơn SPX Express', 'spx-express-woocommerce' ) . '</h3>';
		self::row( __( 'Mã vận đơn', 'spx-express-woocommerce' ), $tracking );
		self::row( __( 'Trạng thái vận chuyển', 'spx-express-woocommerce' ), $order->get_meta( '_spx_shipment_status', true ) );
		self::row( __( 'Ngày tạo vận đơn', 'spx-express-woocommerce' ), $order->get_meta( '_spx_shipment_created_at', true ) );
		self::row( __( 'Đồng bộ gần nhất', 'spx-express-woocommerce' ), $order->get_meta( '_spx_last_sync_at', true ) );
		echo '</div>';
	}

	private static function row( string $label, $value ) {
		echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ?: '—' ) . '</p>';
	}

	public static function render_customer_tracking( $order ) {
		if ( ! $order instanceof WC_Order ) { return; }
		$tracking = $order->get_meta( '_spx_tracking_number', true );
		if ( ! $tracking ) { return; }
		echo '<section class="woocommerce-order-details spx-customer-tracking"><h2>' . esc_html__( 'Shipment tracking', 'spx-express-woocommerce' ) . '</h2>';
		self::row( __( 'Carrier', 'spx-express-woocommerce' ), __( 'SPX Express', 'spx-express-woocommerce' ) );
		self::row( __( 'Tracking number', 'spx-express-woocommerce' ), $tracking );
		$label = (string) $order->get_meta( '_spx_shipment_status_label', true );
		if ( '' === $label ) {
			$code = (string) $order->get_meta( '_spx_shipment_status_code', true );
			$label = '' !== $code ? SPX_Tracking_Status_Mapper::map( $code )['customer_label'] : (string) $order->get_meta( '_spx_shipment_status', true );
		}
		self::row( __( 'Current status', 'spx-express-woocommerce' ), $label );
		$link = (string) $order->get_meta( '_spx_tracking_link', true );
		if ( '' !== $link && 'https' === strtolower( (string) wp_parse_url( $link, PHP_URL_SCHEME ) ) ) {
			echo '<p><strong>' . esc_html__( 'Tracking link', 'spx-express-woocommerce' ) . ':</strong> <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link ) . '</a></p>';
		}
		self::row( __( 'Last updated', 'spx-express-woocommerce' ), $order->get_meta( '_spx_last_sync_at', true ) );
		echo '</section>';
	}

	public static function enqueue_admin_style( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'woocommerce_page_wc-orders', 'woocommerce_page_spx-express-address' ), true ) ) { return; }
		$css = SPX_WC_PATH . 'assets/css/admin.css';
		$js  = SPX_WC_PATH . 'assets/js/admin.js';
		wp_enqueue_style( 'spx-express-admin', plugins_url( 'assets/css/admin.css', SPX_WC_FILE ), array(), is_file( $css ) ? (string) filemtime( $css ) : SPX_WC_VERSION );
		wp_enqueue_script( 'spx-express-admin', plugins_url( 'assets/js/admin.js', SPX_WC_FILE ), array(), is_file( $js ) ? (string) filemtime( $js ) : SPX_WC_VERSION, true );
		wp_localize_script( 'spx-express-admin', 'spxAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( SPX_Admin_Address::NONCE_AJAX ),
		) );
	}
}
