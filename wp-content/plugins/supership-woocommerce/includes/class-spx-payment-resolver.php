<?php
defined( 'ABSPATH' ) || exit;

/** Canonical, side-effect-free WooCommerce payment state for SPX shipments. */
final class SPX_Payment_Resolver {
	/** @return array{payment_method:string,is_paid:bool,needs_payment:bool,payment_state:string,cod_amount:?int,ready_for_shipment:bool,reason:string} */
	public static function resolve( WC_Order $order ): array {
		$payment_method = strtolower( trim( (string) $order->get_payment_method() ) );
		$is_paid        = (bool) $order->is_paid();
		$needs_payment  = method_exists( $order, 'needs_payment' ) ? (bool) $order->needs_payment() : ! $is_paid;
		$status         = strtolower( trim( (string) $order->get_status() ) );

		$base = array(
			'payment_method'    => $payment_method,
			'is_paid'           => $is_paid,
			'needs_payment'     => $needs_payment,
			'payment_state'     => 'blocked',
			'cod_amount'        => null,
			'ready_for_shipment'=> false,
			'reason'            => '',
		);

		if ( in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			$base['reason'] = 'order_status_blocked';
			return $base;
		}

		if ( $is_paid ) {
			if ( ! self::valid_total( $order->get_total() ) ) {
				$base['payment_state'] = 'invalid';
				$base['reason'] = 'invalid_order_total';
				return $base;
			}
			$base['payment_state']      = 'paid';
			$base['cod_amount']         = 0;
			$base['ready_for_shipment'] = true;
			return $base;
		}

		if ( 'cod' === $payment_method ) {
			$total = $order->get_total();
			if ( ! self::valid_total( $total ) ) {
				$base['payment_state'] = 'invalid';
				$base['reason'] = 'invalid_order_total';
				return $base;
			}
			$base['payment_state']      = 'cash_on_delivery';
			$base['cod_amount']         = (int) round( (float) $total );
			$base['ready_for_shipment'] = true;
			return $base;
		}

		if ( '' === $payment_method ) {
			$base['payment_state'] = 'missing';
			$base['reason'] = 'payment_method_missing';
			return $base;
		}

		$base['payment_state'] = 'unconfirmed';
		$base['reason'] = 'payment_not_confirmed';
		return $base;
	}

	private static function valid_total( $total ): bool {
		if ( null === $total || ! is_numeric( $total ) ) { return false; }
		$value = (float) $total;
		return is_finite( $value ) && $value >= 0;
	}
}
