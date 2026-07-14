<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-only manual SPX shipping-fee check for a single order. Uses the core
 * WooCommerce "Order actions" mechanism (capability + nonce, POST, never auto).
 * Persists a safe quote snapshot via WC_Order CRUD and renders raw values only.
 * Never touches Cart/Checkout totals or the customer shipping charge.
 */
final class SPX_Admin_Rate {
	const CAP          = 'edit_shop_orders';
	const ACTION       = 'spx_check_rate';
	const STALE_SECONDS = 1800; // 30 minutes

	const M_ESTIMATED = '_spx_rate_estimated_shipping_fee';
	const M_BASIC     = '_spx_rate_basic_shipping_fee';
	const M_COD_FEE   = '_spx_rate_cod_service_fee';
	const M_HV_FEE    = '_spx_rate_high_value_processing_fee';
	const M_VAT       = '_spx_rate_vat_fee';
	const M_VOUCHER   = '_spx_rate_voucher_shipping_fee';
	const M_EDT_MIN   = '_spx_rate_edt_min';
	const M_EDT_MAX   = '_spx_rate_edt_max';
	const M_CHECKED   = '_spx_rate_checked_at';
	const M_ENV       = '_spx_rate_environment';
	const M_STATUS    = '_spx_rate_status';
	const M_ERR_CODE  = '_spx_rate_last_error_code';
	const M_ERR_AT    = '_spx_rate_last_error_at';

	public static function init() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_action' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( __CLASS__, 'run_check' ) );
	}

	/** Offer the action only in test mode with account credentials + a complete sender. */
	public static function add_action( array $actions ): array {
		if ( ! current_user_can( self::CAP ) ) { return $actions; }
		$config = SPX_API_Config::for_test();
		if ( SPX_API_Config::TEST_ENV === $config->get_environment() && $config->has_account_credentials() && SPX_Sender_Profile::is_complete() ) {
			$actions[ self::ACTION ] = __( 'Kiểm tra phí SPX', 'spx-express-woocommerce' );
		}
		return $actions;
	}

	public static function run_check( $order ) {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) { return; }

		$repo       = new SPX_Address_Repository();
		$profile    = SPX_Sender_Profile::get();
		$resolution = ( new SPX_WC_Address_Resolver( $repo ) )->resolve( $order );
		$payload    = SPX_Order_Mapper::build( $order, SPX_Settings::get_instance_settings() );
		$shipment   = SPX_Order_Mapper::enrich_for_spx( $payload, $profile, $resolution );
		$shipment['service_type'] = (int) $profile['service_type'];
		$shipment['collect_type'] = (int) $profile['collect_type'];

		$quote = ( new SPX_Api_Provider() )->calculate_rate( $shipment );

		if ( empty( $quote['success'] ) ) {
			$order->update_meta_data( self::M_ERR_CODE, sanitize_key( $quote['error_code'] ?? 'error' ) );
			$order->update_meta_data( self::M_ERR_AT, gmdate( 'c' ) );
			$order->add_order_note( sprintf( __( 'Kiểm tra phí SPX thất bại: %s', 'spx-express-woocommerce' ), sanitize_text_field( $quote['message'] ?? '' ) ) );
			$order->save();
			SPX_Logger::log( 'warning', 'rate_check_failed', $order->get_id(), '', 'code=' . ( $quote['error_code'] ?? '' ) . ' ret=' . ( $quote['ret_code'] ?? '' ) );
			return;
		}

		$map = array(
			self::M_ESTIMATED => 'estimated_shipping_fee', self::M_BASIC => 'basic_shipping_fee',
			self::M_COD_FEE => 'cod_service_fee', self::M_HV_FEE => 'high_value_processing_fee',
			self::M_VAT => 'vat_fee', self::M_VOUCHER => 'voucher_shipping_fee',
			self::M_EDT_MIN => 'edt_min', self::M_EDT_MAX => 'edt_max',
		);
		foreach ( $map as $meta => $key ) {
			if ( array_key_exists( $key, $quote ) ) { $order->update_meta_data( $meta, $quote[ $key ] ); }
		}
		$order->update_meta_data( self::M_CHECKED, sanitize_text_field( $quote['checked_at'] ?? gmdate( 'c' ) ) );
		$order->update_meta_data( self::M_ENV, sanitize_key( $quote['environment'] ?? 'test' ) );
		$order->update_meta_data( self::M_STATUS, 'success' );
		$order->delete_meta_data( self::M_ERR_CODE );
		$order->add_order_note( sprintf( __( 'Đã kiểm tra phí SPX (test): phí dự kiến (raw) %s — đơn vị chưa được SPX xác nhận.', 'spx-express-woocommerce' ), (string) ( 0 + $quote['estimated_shipping_fee'] ) ) );
		$order->save();
		SPX_Logger::log( 'info', 'rate_check_success', $order->get_id(), '', 'estimated=' . (int) $quote['estimated_shipping_fee'] . ' env=test' );
	}

	public static function render_quote( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( self::CAP ) ) { return; }
		$estimated = $order->get_meta( self::M_ESTIMATED, true );
		echo '<div class="spx-rate-quote"><h3>' . esc_html__( 'Báo phí SPX', 'spx-express-woocommerce' ) . '</h3>';
		$checkout_source = (string) $order->get_meta( '_spx_rate_source', true );
		if ( '' !== $checkout_source ) { self::render_checkout_audit( $order, $checkout_source ); }

		if ( 'success' !== $order->get_meta( self::M_STATUS, true ) || '' === (string) $estimated ) {
			$err = $order->get_meta( self::M_ERR_CODE, true );
			if ( '' === $checkout_source ) { echo '<p>' . esc_html__( 'Chưa kiểm tra phí SPX.', 'spx-express-woocommerce' ) . '</p>'; }
			if ( $err ) { echo '<p><em>' . esc_html__( 'Lần kiểm tra gần nhất chưa thành công.', 'spx-express-woocommerce' ) . '</em></p>'; }
			echo '</div>';
			return;
		}

		// SPX has not documented the fee currency/unit, so show RAW values
		// (never wc_price / ₫) with an explicit unconfirmed-unit warning.
		echo '<p style="color:#b26a00;"><strong>' . esc_html__( 'SPX raw fee — đơn vị chưa được SPX xác nhận.', 'spx-express-woocommerce' ) . '</strong></p>';
		echo '<table class="spx-rate-table">';
		self::fee_row( __( 'Phí vận chuyển dự kiến (raw)', 'spx-express-woocommerce' ), $estimated );
		self::fee_row( __( 'Phí cơ bản (raw)', 'spx-express-woocommerce' ), $order->get_meta( self::M_BASIC, true ) );
		self::fee_row( __( 'Phí COD (raw)', 'spx-express-woocommerce' ), $order->get_meta( self::M_COD_FEE, true ) );
		self::fee_row( __( 'Phí giá trị cao (raw)', 'spx-express-woocommerce' ), $order->get_meta( self::M_HV_FEE, true ) );
		self::fee_row( __( 'VAT (raw)', 'spx-express-woocommerce' ), $order->get_meta( self::M_VAT, true ) );
		self::fee_row( __( 'Giảm voucher (raw)', 'spx-express-woocommerce' ), $order->get_meta( self::M_VOUCHER, true ) );
		echo '</table>';

		$checked = $order->get_meta( self::M_CHECKED, true );
		echo '<p><strong>' . esc_html__( 'Environment', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html__( 'Test', 'spx-express-woocommerce' ) . '</p>';
		if ( $checked ) {
			echo '<p><strong>' . esc_html__( 'Lần kiểm tra', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( $checked ) . '</p>';
			$ts = strtotime( $checked );
			if ( $ts && ( time() - $ts ) > self::STALE_SECONDS ) {
				echo '<p><em>' . esc_html__( 'Báo phí có thể đã cũ.', 'spx-express-woocommerce' ) . '</em></p>';
			}
		}
		echo '<p><em>' . esc_html__( 'Báo phí chỉ để tham khảo cho admin; không ảnh hưởng tổng đơn hay phí khách trả.', 'spx-express-woocommerce' ) . '</em></p>';
		echo '</div>';
	}

	public static function checkout_source_label( string $source ): string {
		$labels = array(
			'spx_dynamic'              => __( 'SPX động', 'spx-express-woocommerce' ),
			'fallback_fixed'           => __( 'Phí cố định dự phòng', 'spx-express-woocommerce' ),
			'fixed_disabled_experiment'=> __( 'Phí cố định', 'spx-express-woocommerce' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Phí cố định', 'spx-express-woocommerce' );
	}

	private static function render_checkout_audit( WC_Order $order, string $source ): void {
		echo '<div class="spx-checkout-rate-audit">';
		echo '<p><strong>' . esc_html__( 'Checkout rate source', 'spx-express-woocommerce' ) . ':</strong> ' . esc_html( self::checkout_source_label( $source ) ) . '</p>';
		echo '<p style="color:#b26a00;"><strong>' . esc_html__( 'Experimental sandbox conversion: fee unit/currency remain unconfirmed; multiplier 1000 is not authorized for production customer charges.', 'spx-express-woocommerce' ) . '</strong></p>';
		echo '<table class="spx-rate-table">';
		$raw = array(
			'_spx_rate_raw_estimated' => 'Estimated shipping fee (raw)', '_spx_rate_raw_basic' => 'Basic fee (raw)',
			'_spx_rate_raw_vat' => 'VAT (raw)', '_spx_rate_raw_cod' => 'COD fee (raw)',
			'_spx_rate_raw_high_value' => 'High-value fee (raw)', '_spx_rate_raw_voucher' => 'Voucher fee (raw)',
		);
		foreach ( $raw as $key => $label ) { self::fee_row( $label, $order->get_meta( $key, true ) ); }
		echo '</table>';
		$converted = $order->get_meta( '_spx_rate_converted_vnd', true );
		if ( '' !== (string) $converted && is_numeric( $converted ) ) {
			echo '<p><strong>' . esc_html__( 'Converted checkout candidate', 'spx-express-woocommerce' ) . ':</strong> ' . wp_kses_post( wc_price( $converted ) ) . '</p>';
		}
		foreach ( array( '_spx_rate_environment'=>'Environment', '_spx_rate_multiplier'=>'Multiplier', '_spx_rate_unit_mode'=>'Unit mode', '_spx_rate_contract_version'=>'Contract', '_spx_rate_cache_hit'=>'Cache hit', '_spx_rate_quoted_at'=>'Quoted at', '_spx_rate_fallback_reason'=>'Fallback reason' ) as $key=>$label ) {
			$value=$order->get_meta($key,true); if(''!==(string)$value){echo '<p><strong>'.esc_html($label).':</strong> '.esc_html((string)$value).'</p>';}
		}
		echo '</div>';
	}

	private static function fee_row( string $label, $value ) {
		if ( '' === (string) $value || ! is_numeric( $value ) ) { return; }
		// Raw numeric value only — no currency symbol and no wc_price.
		echo '<tr><th style="text-align:left;padding-right:12px;">' . esc_html( $label ) . '</th><td>' . esc_html( (string) ( 0 + $value ) ) . '</td></tr>';
	}
}
