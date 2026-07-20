<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure, network-free checklist of what still blocks an SPX shipment. Used by the
 * admin order screen to show "ready" or the list of missing fields. It never
 * calls any API and never creates a shipment.
 */
final class SPX_Shipment_Readiness {
	const MAX_WEIGHT_GRAMS = 17000;      // VN create-order limit
	const MAX_COD_VND      = 20000000;   // VN COD limit

	/**
	 * @param array $ctx { sender:array, recipient:array, parcel:array, payment:array }
	 * @return array{ready:bool,errors:array<int,array{code:string,field:string,message:string}>}
	 */
	public static function evaluate( array $ctx ): array {
		$errors = array();
		$sender = isset( $ctx['sender'] ) && is_array( $ctx['sender'] ) ? $ctx['sender'] : array();
		$rcpt   = isset( $ctx['recipient'] ) && is_array( $ctx['recipient'] ) ? $ctx['recipient'] : array();
		$parcel = isset( $ctx['parcel'] ) && is_array( $ctx['parcel'] ) ? $ctx['parcel'] : array();
		$payment = isset( $ctx['payment'] ) && is_array( $ctx['payment'] ) ? $ctx['payment'] : array();

		$need = function ( $arr, $key ) { return '' === trim( (string) ( $arr[ $key ] ?? '' ) ); };

		// Sender.
		if ( $need( $sender, 'sender_name' ) ) { $errors[] = self::e( 'sender_name_missing', 'sender.name', __( 'Sender name is not configured.', 'spx-express-woocommerce' ) ); }
		if ( $need( $sender, 'sender_phone' ) ) { $errors[] = self::e( 'sender_phone_missing', 'sender.phone', __( 'Sender phone is not configured.', 'spx-express-woocommerce' ) ); }
		if ( $need( $sender, 'sender_detail_address' ) ) { $errors[] = self::e( 'sender_address_missing', 'sender.address', __( 'Sender detail address is not configured.', 'spx-express-woocommerce' ) ); }
		if ( $need( $sender, 'sender_province_code' ) ) { $errors[] = self::e( 'sender_province_missing', 'sender.province', __( 'Sender province is not configured.', 'spx-express-woocommerce' ) ); }
		if ( $need( $sender, 'sender_district_code' ) ) { $errors[] = self::e( 'sender_district_missing', 'sender.district', __( 'Sender district is not configured.', 'spx-express-woocommerce' ) ); }
		if ( $need( $sender, 'sender_ward_code' ) ) { $errors[] = self::e( 'sender_ward_missing', 'sender.ward', __( 'Sender ward is not configured.', 'spx-express-woocommerce' ) ); }

		// Recipient.
		if ( $need( $rcpt, 'name' ) ) { $errors[] = self::e( 'recipient_name_missing', 'recipient.name', __( 'Recipient name is missing.', 'spx-express-woocommerce' ) ); }
		if ( $need( $rcpt, 'phone' ) ) { $errors[] = self::e( 'recipient_phone_missing', 'recipient.phone', __( 'Recipient phone is missing.', 'spx-express-woocommerce' ) ); }
		if ( $need( $rcpt, 'address' ) ) { $errors[] = self::e( 'recipient_address_missing', 'recipient.address', __( 'Recipient detail address is missing.', 'spx-express-woocommerce' ) ); }
		if ( $need( $rcpt, 'province_code' ) ) { $errors[] = self::e( 'recipient_province_unresolved', 'recipient.province', __( 'Recipient SPX province is not resolved.', 'spx-express-woocommerce' ) ); }
		if ( $need( $rcpt, 'district_code' ) ) { $errors[] = self::e( 'recipient_district_unresolved', 'recipient.district', __( 'Recipient SPX district is not resolved.', 'spx-express-woocommerce' ) ); }
		if ( $need( $rcpt, 'ward_code' ) ) { $errors[] = self::e( 'recipient_ward_unresolved', 'recipient.ward', __( 'Recipient SPX ward/commune is not resolved.', 'spx-express-woocommerce' ) ); }
		if ( array_key_exists( 'service_status', $rcpt ) && 'available' !== strtolower( trim( (string) $rcpt['service_status'] ) ) ) { $errors[] = self::e( 'recipient_service_unavailable', 'recipient.service_status', __( 'Recipient SPX ward is not currently available.', 'spx-express-woocommerce' ) ); }
		if ( array_key_exists( 'delivery_supported', $rcpt ) && ! $rcpt['delivery_supported'] ) { $errors[] = self::e( 'recipient_delivery_unsupported', 'recipient.delivery', __( 'Recipient SPX ward does not support delivery.', 'spx-express-woocommerce' ) ); }

		// Parcel — prefer canonical parcel errors (already Vietnamese, policy-aware).
		$parcel_errors = isset( $parcel['errors'] ) && is_array( $parcel['errors'] ) ? $parcel['errors'] : array();
		if ( $parcel_errors ) {
			foreach ( $parcel_errors as $pe ) {
				$errors[] = self::e( (string) ( $pe['code'] ?? 'parcel_invalid' ), 'parcel', (string) ( $pe['message'] ?? __( 'Kiện hàng chưa hợp lệ để tạo vận đơn SPX.', 'spx-express-woocommerce' ) ) );
			}
		} else {
			$weight = (int) ( $parcel['weight_grams'] ?? 0 );
			if ( $weight < 1 ) { $errors[] = self::e( 'parcel_weight_invalid', 'parcel.weight', __( 'Sản phẩm chưa có cân nặng.', 'spx-express-woocommerce' ) ); }
			elseif ( $weight > self::MAX_WEIGHT_GRAMS ) { $errors[] = self::e( 'parcel_weight_exceeded', 'parcel.weight', __( 'Kiện hàng vượt giới hạn cân nặng SPX.', 'spx-express-woocommerce' ) ); }
			if ( (int) ( $parcel['item_quantity'] ?? 0 ) < 1 ) { $errors[] = self::e( 'parcel_quantity_invalid', 'parcel.quantity', __( 'Đơn hàng không có sản phẩm cần giao.', 'spx-express-woocommerce' ) ); }
		}

		// Payment/COD — canonical state from SPX_Payment_Resolver only.
		if ( empty( $payment ) ) {
			$errors[] = self::e( 'payment_context_missing', 'payment', __( 'Không xác định được trạng thái thanh toán. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ) );
		} elseif ( empty( $payment['ready_for_shipment'] ) ) {
			$reason = sanitize_key( (string) ( $payment['reason'] ?? 'payment_not_confirmed' ) );
			$messages = array(
				'payment_method_missing' => __( 'Đơn hàng chưa có phương thức thanh toán. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
				'payment_not_confirmed'  => __( 'Thanh toán trực tuyến chưa được xác nhận. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
				'order_status_blocked'   => __( 'Trạng thái đơn hàng không cho phép tạo vận đơn SPX mới.', 'spx-express-woocommerce' ),
				'invalid_order_total'    => __( 'Tổng tiền đơn hàng không hợp lệ. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
			);
			if ( ! isset( $messages[ $reason ] ) ) { $reason = 'payment_not_confirmed'; }
			$errors[] = self::e( $reason, 'payment', $messages[ $reason ] );
		} elseif ( 'cash_on_delivery' === (string) ( $payment['payment_state'] ?? '' ) ) {
			if ( array_key_exists( 'cod_supported', $rcpt ) && ! $rcpt['cod_supported'] ) { $errors[] = self::e( 'recipient_cod_unsupported', 'recipient.cod', __( 'Recipient SPX ward does not support COD.', 'spx-express-woocommerce' ) ); }
			$amount = $payment['cod_amount'] ?? null;
			if ( ! is_int( $amount ) && ! ( is_string( $amount ) && ctype_digit( $amount ) ) ) {
				$errors[] = self::e( 'cod_amount_invalid', 'cod.amount', __( 'COD amount must be a whole number (VND).', 'spx-express-woocommerce' ) );
			} elseif ( (int) $amount < 0 ) {
				$errors[] = self::e( 'cod_amount_negative', 'cod.amount', __( 'COD amount cannot be negative.', 'spx-express-woocommerce' ) );
			} elseif ( (int) $amount > self::MAX_COD_VND ) {
				$errors[] = self::e( 'cod_amount_exceeded', 'cod.amount', __( 'COD amount exceeds the SPX limit of 20,000,000 VND.', 'spx-express-woocommerce' ) );
			}
		}

		return array( 'ready' => empty( $errors ), 'errors' => $errors );
	}

	/** Canonical customer-safe explanation for payment readiness. */
	public static function payment_message( string $reason ): string {
		$messages = array(
			'payment_context_missing' => __( 'Không xác định được trạng thái thanh toán. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
			'payment_method_missing'  => __( 'Đơn hàng chưa có phương thức thanh toán. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
			'payment_not_confirmed'   => __( 'Thanh toán trực tuyến chưa được xác nhận. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
			'order_status_blocked'    => __( 'Trạng thái đơn hàng không cho phép tạo vận đơn SPX mới.', 'spx-express-woocommerce' ),
			'invalid_order_total'     => __( 'Tổng tiền đơn hàng không hợp lệ. Chưa thể tạo vận đơn SPX.', 'spx-express-woocommerce' ),
		);
		return $messages[ $reason ] ?? $messages['payment_not_confirmed'];
	}

	private static function e( string $code, string $field, string $message ): array {
		return array( 'code' => $code, 'field' => $field, 'message' => $message );
	}
}
