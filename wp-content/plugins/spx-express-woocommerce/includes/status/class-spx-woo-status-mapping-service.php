<?php
defined( 'ABSPATH' ) || exit;

/** Applies one policy-controlled WooCommerce status transition per canonical SPX transition. */
final class SPX_Woo_Status_Mapping_Service {
	const M_CODE     = '_spx_last_mapped_status_code';
	const M_WOO      = '_spx_last_mapped_woo_status';
	const M_AT       = '_spx_last_mapping_at';
	const M_SOURCE   = '_spx_last_mapping_source';
	const M_RESULT   = '_spx_last_mapping_result';
	const M_VERSION  = '_spx_last_mapping_settings_version';

	/** @var array<int,bool> */
	private static $in_progress = array();

	public function apply( WC_Order $order, string $old_code, string $new_code, string $source, int $event_timestamp = 0 ): array {
		$old_code = sanitize_key( $old_code );
		$new_code = sanitize_key( $new_code );
		$source   = $this->normalize_source( $source );
		if ( '' === $new_code || $old_code === $new_code ) {
			return $this->response( 'same_status', false, '', $source );
		}

		$order_id = (int) $order->get_id();
		if ( isset( self::$in_progress[ $order_id ] ) ) {
			return $this->response( 'in_progress', false, '', $source );
		}

		$last_code = (string) $order->get_meta( self::M_CODE, true );
		if ( $new_code === $last_code ) {
			$last_woo = (string) $order->get_meta( self::M_WOO, true );
			$last_result = (string) $order->get_meta( self::M_RESULT, true );
			$result = 'applied' === $last_result && '' !== $last_woo && $order->get_status() !== $last_woo
				? 'manual_override_preserved' : 'already_applied';
			return $this->response( $result, false, $last_woo, $source );
		}

		self::$in_progress[ $order_id ] = true;
		try {
			$mapping = SPX_Woo_Status_Mapping_Policy::mapping( $new_code );
			$current = sanitize_key( $order->get_status() );
			if ( '' === $mapping['target'] ) {
				return $this->audit( $order, $new_code, $current, $source, 'no_action', false );
			}
			if ( ! $mapping['enabled'] ) {
				return $this->audit( $order, $new_code, $current, $source, 'disabled', false );
			}
			if ( $current === $mapping['target'] ) {
				return $this->audit( $order, $new_code, $current, $source, 'already_applied', false );
			}
			if ( ! in_array( $current, $mapping['allowed_sources'], true ) ) {
				return $this->audit( $order, $new_code, $current, $source, 'protected_status', false );
			}

			$target = $mapping['target'];
			$this->set_audit_meta( $order, $new_code, $target, $source, 'applied' );
			$order->update_status( $target, '', false );
			$order->add_order_note( $this->note( $new_code, $current, $target ) );
			$order->save();
			return $this->response( 'applied', true, $target, $source );
		} catch ( Throwable $error ) {
			try {
				$this->set_audit_meta( $order, $new_code, sanitize_key( $order->get_status() ), $source, 'error' );
				$order->save();
			} catch ( Throwable $ignored ) {
				// Tracking state was already persisted; never turn an audit failure into a sync fatal.
			}
			if ( class_exists( 'SPX_Logger' ) ) {
				SPX_Logger::log( 'error', 'woo_status_mapping_error', $order_id, '', 'code=' . $new_code . ' source=' . $source );
			}
			return $this->response( 'error', false, '', $source );
		} finally {
			unset( self::$in_progress[ $order_id ] );
		}
	}

	private function audit( WC_Order $order, string $code, string $woo_status, string $source, string $result, bool $applied ): array {
		$this->set_audit_meta( $order, $code, $woo_status, $source, $result );
		$order->save();
		return $this->response( $result, $applied, $woo_status, $source );
	}

	private function set_audit_meta( WC_Order $order, string $code, string $woo_status, string $source, string $result ) {
		$order->update_meta_data( self::M_CODE, $code );
		$order->update_meta_data( self::M_WOO, $woo_status );
		$order->update_meta_data( self::M_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::M_SOURCE, $source );
		$order->update_meta_data( self::M_RESULT, $result );
		$order->update_meta_data( self::M_VERSION, SPX_Woo_Status_Mapping_Policy::SETTINGS_VERSION );
	}

	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, array( 'scheduler', 'manual', 'webhook' ), true ) ? $source : 'unknown';
	}

	private function response( string $result, bool $applied, string $target, string $source ): array {
		return array( 'result' => $result, 'applied' => $applied, 'target' => $target, 'source' => $source );
	}

	private function note( string $code, string $from, string $target ): string {
		if ( '4001' === $code ) {
			return sprintf( __( 'SPX xác nhận giao hàng thành công. Đơn WooCommerce được chuyển từ %1$s sang %2$s.', 'spx-express-woocommerce' ), wc_get_order_status_name( $from ), wc_get_order_status_name( $target ) );
		}
		if ( '7001' === $code ) {
			return __( 'SPX báo vận đơn đã hủy. Đơn WooCommerce được chuyển sang Đã hủy theo cấu hình của cửa hàng.', 'spx-express-woocommerce' );
		}
		return sprintf( __( 'SPX báo trạng thái ngoại lệ (%1$s). Đơn WooCommerce được chuyển sang %2$s để quản trị viên kiểm tra.', 'spx-express-woocommerce' ), $code, wc_get_order_status_name( $target ) );
	}
}

