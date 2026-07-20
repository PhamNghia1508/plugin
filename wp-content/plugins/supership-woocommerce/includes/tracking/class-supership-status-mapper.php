<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Status Mapper
 * 
 * Maps SuperShip's 28 order statuses to WooCommerce order statuses.
 * 
 * SuperShip statuses (from API docs):
 * 0=Huỷ, 1=Chờ Duyệt, 2=Chờ Lấy Hàng, 3=Đang Lấy Hàng, 4=Đã Lấy Hàng,
 * 5=Hoãn Lấy Hàng, 6=Không Lấy Được, 7=Đang Nhập Kho, 8=Đã Nhập Kho,
 * 9=Đang Chuyển Kho Giao, 10=Đã Chuyển Kho Giao, 11=Đang Giao Hàng,
 * 12=Đã Giao Hàng Toàn Bộ, 13=Đã Giao Hàng Một Phần, 14=Hoãn Giao Hàng,
 * 15=Không Giao Được, 16=Đã Đối Soát Giao Hàng, 17=Đã Đối Soát Trả Hàng,
 * 18=Đang Chuyển Kho Trả, 19=Đã Chuyển Kho Trả, 20=Đang Trả Hàng,
 * 21=Đã Trả Hàng, 22=Hoãn Trả Hàng, 23=Đang Vận Chuyển, 24=Xác Nhận Hoàn,
 * 25=Hàng Thất Lạc, 26=Không Trả Được, 27=Đã Bồi Hoàn
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Status_Mapper {
	
	/**
	 * Get all SuperShip statuses
	 * 
	 * @return array {code => name}
	 */
	public static function get_supership_statuses(): array {
		return array(
			0  => __( 'Huỷ', 'supership-woocommerce' ),
			1  => __( 'Chờ Duyệt', 'supership-woocommerce' ),
			2  => __( 'Chờ Lấy Hàng', 'supership-woocommerce' ),
			3  => __( 'Đang Lấy Hàng', 'supership-woocommerce' ),
			4  => __( 'Đã Lấy Hàng', 'supership-woocommerce' ),
			5  => __( 'Hoãn Lấy Hàng', 'supership-woocommerce' ),
			6  => __( 'Không Lấy Được', 'supership-woocommerce' ),
			7  => __( 'Đang Nhập Kho', 'supership-woocommerce' ),
			8  => __( 'Đã Nhập Kho', 'supership-woocommerce' ),
			9  => __( 'Đang Chuyển Kho Giao', 'supership-woocommerce' ),
			10 => __( 'Đã Chuyển Kho Giao', 'supership-woocommerce' ),
			11 => __( 'Đang Giao Hàng', 'supership-woocommerce' ),
			12 => __( 'Đã Giao Hàng Toàn Bộ', 'supership-woocommerce' ),
			13 => __( 'Đã Giao Hàng Một Phần', 'supership-woocommerce' ),
			14 => __( 'Hoãn Giao Hàng', 'supership-woocommerce' ),
			15 => __( 'Không Giao Được', 'supership-woocommerce' ),
			16 => __( 'Đã Đối Soát Giao Hàng', 'supership-woocommerce' ),
			17 => __( 'Đã Đối Soát Trả Hàng', 'supership-woocommerce' ),
			18 => __( 'Đang Chuyển Kho Trả', 'supership-woocommerce' ),
			19 => __( 'Đã Chuyển Kho Trả', 'supership-woocommerce' ),
			20 => __( 'Đang Trả Hàng', 'supership-woocommerce' ),
			21 => __( 'Đã Trả Hàng', 'supership-woocommerce' ),
			22 => __( 'Hoãn Trả Hàng', 'supership-woocommerce' ),
			23 => __( 'Đang Vận Chuyển', 'supership-woocommerce' ),
			24 => __( 'Xác Nhận Hoàn', 'supership-woocommerce' ),
			25 => __( 'Hàng Thất Lạc', 'supership-woocommerce' ),
			26 => __( 'Không Trả Được', 'supership-woocommerce' ),
			27 => __( 'Đã Bồi Hoàn', 'supership-woocommerce' ),
		);
	}

	/**
	 * Map SuperShip status to WooCommerce order status
	 * 
	 * @param int $supership_status SuperShip status code (0-27)
	 * @return string WooCommerce status (without 'wc-' prefix)
	 */
	public static function to_wc_status( int $supership_status ): string {
		$mapping = array(
			// Cancelled
			0  => 'cancelled',        // Huỷ
			
			// Processing (pending pickup/warehousing)
			1  => 'processing',       // Chờ Duyệt
			2  => 'processing',       // Chờ Lấy Hàng
			3  => 'processing',       // Đang Lấy Hàng
			4  => 'processing',       // Đã Lấy Hàng
			5  => 'on-hold',          // Hoãn Lấy Hàng
			6  => 'failed',           // Không Lấy Được
			
			// In transit (warehousing)
			7  => 'processing',       // Đang Nhập Kho
			8  => 'processing',       // Đã Nhập Kho
			9  => 'processing',       // Đang Chuyển Kho Giao
			10 => 'processing',       // Đã Chuyển Kho Giao
			
			// Delivering
			11 => 'processing',       // Đang Giao Hàng
			
			// Delivered
			12 => 'completed',        // Đã Giao Hàng Toàn Bộ
			13 => 'completed',        // Đã Giao Hàng Một Phần
			
			// Delivery issues
			14 => 'on-hold',          // Hoãn Giao Hàng
			15 => 'on-hold',          // Không Giao Được
			
			// Reconciliation
			16 => 'completed',        // Đã Đối Soát Giao Hàng
			17 => 'refunded',         // Đã Đối Soát Trả Hàng
			
			// Return flow
			18 => 'refunded',         // Đang Chuyển Kho Trả
			19 => 'refunded',         // Đã Chuyển Kho Trả
			20 => 'refunded',         // Đang Trả Hàng
			21 => 'refunded',         // Đã Trả Hàng
			22 => 'on-hold',          // Hoãn Trả Hàng
			
			// In transit
			23 => 'processing',       // Đang Vận Chuyển
			
			// Special cases
			24 => 'refunded',         // Xác Nhận Hoàn
			25 => 'failed',           // Hàng Thất Lạc
			26 => 'failed',           // Không Trả Được
			27 => 'refunded',         // Đã Bồi Hoàn
		);
		
		return $mapping[ $supership_status ] ?? 'processing';
	}

	/**
	 * Check if status should trigger WooCommerce status update
	 * 
	 * @param int $supership_status SuperShip status code
	 * @return bool
	 */
	public static function should_update_wc_status( int $supership_status ): bool {
		// Only update for significant state changes
		$update_statuses = array(
			0,  // Cancelled
			4,  // Picked up
			6,  // Pickup failed
			11, // Out for delivery
			12, // Delivered (full)
			13, // Delivered (partial)
			15, // Delivery failed
			16, // Reconciled (delivered)
			17, // Reconciled (returned)
			21, // Returned
			25, // Lost
			27, // Compensated
		);
		
		return in_array( $supership_status, $update_statuses, true );
	}

	/**
	 * Get status category
	 * 
	 * @param int $supership_status SuperShip status code
	 * @return string Category: pending, picked_up, in_transit, delivering, delivered, returned, cancelled, failed
	 */
	public static function get_status_category( int $supership_status ): string {
		if ( 0 === $supership_status ) {
			return 'cancelled';
		}
		
		if ( in_array( $supership_status, array( 1, 2, 3 ), true ) ) {
			return 'pending';
		}
		
		if ( in_array( $supership_status, array( 4, 5 ), true ) ) {
			return 'picked_up';
		}
		
		if ( 6 === $supership_status ) {
			return 'failed';
		}
		
		if ( in_array( $supership_status, array( 7, 8, 9, 10, 23 ), true ) ) {
			return 'in_transit';
		}
		
		if ( in_array( $supership_status, array( 11, 14 ), true ) ) {
			return 'delivering';
		}
		
		if ( in_array( $supership_status, array( 12, 13, 16 ), true ) ) {
			return 'delivered';
		}
		
		if ( in_array( $supership_status, array( 17, 18, 19, 20, 21, 22, 24, 27 ), true ) ) {
			return 'returned';
		}
		
		if ( in_array( $supership_status, array( 15, 25, 26 ), true ) ) {
			return 'failed';
		}
		
		return 'unknown';
	}

	/**
	 * Check if status is final (no further updates expected)
	 * 
	 * @param int $supership_status SuperShip status code
	 * @return bool
	 */
	public static function is_final_status( int $supership_status ): bool {
		$final_statuses = array(
			0,  // Cancelled
			6,  // Pickup failed
			12, // Delivered (full)
			13, // Delivered (partial)
			16, // Reconciled (delivered)
			17, // Reconciled (returned)
			21, // Returned
			25, // Lost
			26, // Return failed
			27, // Compensated
		);
		
		return in_array( $supership_status, $final_statuses, true );
	}

	/**
	 * Get status display color (for admin UI)
	 * 
	 * @param int $supership_status SuperShip status code
	 * @return string Hex color code
	 */
	public static function get_status_color( int $supership_status ): string {
		$category = self::get_status_category( $supership_status );
		
		$colors = array(
			'pending'    => '#999999',
			'picked_up'  => '#33b5e5',
			'in_transit' => '#0073aa',
			'delivering' => '#ffba00',
			'delivered'  => '#46b450',
			'returned'   => '#dc3232',
			'cancelled'  => '#a00',
			'failed'     => '#d63638',
			'unknown'    => '#666',
		);
		
		return $colors[ $category ] ?? $colors['unknown'];
	}

	/**
	 * Get customer-facing status message
	 * 
	 * @param int    $supership_status SuperShip status code
	 * @param string $tracking_number Tracking number (optional)
	 * @return string Customer message
	 */
	public static function get_customer_message( int $supership_status, string $tracking_number = '' ): string {
		$statuses = self::get_supership_statuses();
		$status_name = $statuses[ $supership_status ] ?? __( 'Chưa rõ', 'supership-woocommerce' );
		
		if ( '' !== $tracking_number ) {
			return sprintf(
				__( 'Đơn hàng %s của bạn hiện đang: %s', 'supership-woocommerce' ),
				$tracking_number,
				$status_name
			);
		}
		
		return sprintf(
			__( 'Trạng thái vận đơn: %s', 'supership-woocommerce' ),
			$status_name
		);
	}

	/**
	 * Check if status should send customer notification
	 * 
	 * @param int $supership_status SuperShip status code
	 * @return bool
	 */
	public static function should_notify_customer( int $supership_status ): bool {
		// Notify on major milestones
		$notify_statuses = array(
			4,  // Picked up
			11, // Out for delivery
			12, // Delivered (full)
			13, // Delivered (partial)
			21, // Returned
		);
		
		return in_array( $supership_status, $notify_statuses, true );
	}
}
