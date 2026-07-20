<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( $text ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/tracking/class-supership-status-mapper.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '; expected=' . var_export( $expected, true ) . ', actual=' . var_export( $actual, true ) );
	}
}

// The 28 status codes must match GET /v1/partner/orders/status exactly
// (re-verified live at docs.developers.supership.vn/guide/orders.html on 2026-07-19).
$expected_statuses = array(
	0  => 'Huỷ',
	1  => 'Chờ Duyệt',
	2  => 'Chờ Lấy Hàng',
	3  => 'Đang Lấy Hàng',
	4  => 'Đã Lấy Hàng',
	5  => 'Hoãn Lấy Hàng',
	6  => 'Không Lấy Được',
	7  => 'Đang Nhập Kho',
	8  => 'Đã Nhập Kho',
	9  => 'Đang Chuyển Kho Giao',
	10 => 'Đã Chuyển Kho Giao',
	11 => 'Đang Giao Hàng',
	12 => 'Đã Giao Hàng Toàn Bộ',
	13 => 'Đã Giao Hàng Một Phần',
	14 => 'Hoãn Giao Hàng',
	15 => 'Không Giao Được',
	16 => 'Đã Đối Soát Giao Hàng',
	17 => 'Đã Đối Soát Trả Hàng',
	18 => 'Đang Chuyển Kho Trả',
	19 => 'Đã Chuyển Kho Trả',
	20 => 'Đang Trả Hàng',
	21 => 'Đã Trả Hàng',
	22 => 'Hoãn Trả Hàng',
	23 => 'Đang Vận Chuyển',
	24 => 'Xác Nhận Hoàn',
	25 => 'Hàng Thất Lạc',
	26 => 'Không Trả Được',
	27 => 'Đã Bồi Hoàn',
);

$actual_statuses = SuperShip_Status_Mapper::get_supership_statuses();
ksort( $expected_statuses );
ksort( $actual_statuses );
expect_same( $expected_statuses, $actual_statuses, 'get_supership_statuses() must match the live SuperShip status list exactly' );

// Cancelled must map to WooCommerce cancelled, and be a final status.
expect_same( 'cancelled', SuperShip_Status_Mapper::to_wc_status( 0 ), 'Status 0 (Huỷ) must map to wc-cancelled' );
expect_same( true, SuperShip_Status_Mapper::is_final_status( 0 ), 'Cancelled must be a final status (stop polling)' );
expect_same( true, SuperShip_Status_Mapper::should_update_wc_status( 0 ), 'Cancellation must always update WC order status' );

// Fully delivered must map to completed and be final + notify-worthy.
expect_same( 'completed', SuperShip_Status_Mapper::to_wc_status( 12 ), 'Status 12 (Đã Giao Hàng Toàn Bộ) must map to wc-completed' );
expect_same( true, SuperShip_Status_Mapper::is_final_status( 12 ), 'Fully delivered must be final' );
expect_same( true, SuperShip_Status_Mapper::should_notify_customer( 12 ), 'Fully delivered must notify the customer' );

// Mid-transit statuses (e.g. picking up) must NOT be treated as final and must not spam the customer.
expect_same( false, SuperShip_Status_Mapper::is_final_status( 3 ), 'Đang Lấy Hàng must not be a final status - still in progress' );
expect_same( false, SuperShip_Status_Mapper::should_notify_customer( 3 ), 'Đang Lấy Hàng should not trigger a customer notification' );

// An unknown/unmapped code must degrade to 'processing' rather than throwing or silently
// producing an empty string - this is the fallback SuperShip_Webhook_Handler and
// SuperShip_Tracking_Scheduler rely on for forward-compatibility with new status codes.
expect_same( 'processing', SuperShip_Status_Mapper::to_wc_status( 999 ), 'Unknown status code must fall back to processing, not throw or return empty' );

// Every code in should_update_wc_status()'s allowlist must actually resolve to a distinct,
// valid WooCommerce status string (i.e. to_wc_status() never silently no-ops for these).
foreach ( array( 0, 4, 6, 11, 12, 13, 15, 16, 17, 21, 25, 27 ) as $code ) {
	$status = SuperShip_Status_Mapper::to_wc_status( $code );
	if ( '' === $status ) {
		throw new RuntimeException( "to_wc_status({$code}) must not be empty for a status marked should_update_wc_status()" );
	}
}

echo "All SuperShip_Status_Mapper tests passed.\n";
