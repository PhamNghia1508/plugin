<?php
defined( 'ABSPATH' ) || exit;

/**
 * Maps official SPX status_code values to an internal status + a Vietnamese
 * customer label + terminal flag. Never changes the WooCommerce order status.
 */
final class SPX_Tracking_Status_Mapper {

	private static function table(): array {
		return array(
			'1001' => array( 'pending_pickup', 'Pending Pickup', 'Chờ lấy hàng', false ),
			'2001' => array( 'in_transit', 'In Transit', 'Đang vận chuyển', false ),
			'2006' => array( 'out_for_delivery', 'Delivering', 'Đang giao hàng', false ),
			'3001' => array( 'on_hold', 'On Hold', 'Tạm giữ', false ),
			'4001' => array( 'delivered', 'Delivered', 'Đã giao', true ),
			'5001' => array( 'pickup_failed', 'Pickup Failed', 'Lấy hàng thất bại', false ),
			'5002' => array( 'damaged', 'Damaged', 'Hư hỏng', true ),
			'5003' => array( 'lost', 'Lost', 'Thất lạc', true ),
			'6001' => array( 'returning', 'Returning', 'Đang hoàn trả', false ),
			'6002' => array( 'return_failed', 'Return Failed', 'Hoàn trả thất bại', false ),
			'6003' => array( 'returned', 'Returned', 'Đã hoàn trả', true ),
			'7001' => array( 'cancelled', 'Cancelled', 'Đã hủy vận đơn', true ),
		);
	}

	/** @return array{status_code:string,official_status:string,internal_status:string,customer_label:string,terminal:bool} */
	public static function map( $status_code, string $official_fallback = '' ): array {
		$code  = (string) $status_code;
		$table = self::table();
		if ( isset( $table[ $code ] ) ) {
			list( $internal, $official, $label, $terminal ) = $table[ $code ];
			return array(
				'status_code'     => $code,
				'official_status' => $official,
				'internal_status' => $internal,
				'customer_label'  => $label,
				'terminal'        => $terminal,
			);
		}
		// Unknown code: safe fallback, never terminal.
		return array(
			'status_code'     => $code,
			'official_status' => '' !== $official_fallback ? $official_fallback : 'Unknown',
			'internal_status' => 'unknown',
			'customer_label'  => __( 'Đang cập nhật', 'spx-express-woocommerce' ),
			'terminal'        => false,
		);
	}
}
