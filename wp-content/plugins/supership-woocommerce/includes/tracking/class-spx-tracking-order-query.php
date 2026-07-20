<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SPX_Order_Environment' ) ) { require_once dirname( __DIR__ ) . '/production/class-spx-order-environment.php'; }

final class SPX_Tracking_Order_Query {
	public function page( int $page = 1, int $limit = 100 ): array {
		$args = array(
			'limit' => max( 1, min( 100, $limit ) ), 'page' => max( 1, $page ), 'paginate' => true,
			'return' => 'objects', 'date_created' => '>' . ( time() - 60 * DAY_IN_SECONDS ),
			'meta_query' => array(
				array( 'key' => '_spx_tracking_number', 'compare' => 'EXISTS' ),
			),
		);
		$query = wc_get_orders( $args );
		$orders = is_object( $query ) && isset( $query->orders ) ? $query->orders : ( is_array( $query ) ? $query : array() );
		$eligible = array_values( array_filter( $orders, array( __CLASS__, 'is_eligible' ) ) );
		return array( 'orders' => $eligible, 'max_num_pages' => is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1 );
	}

	public static function is_eligible( $order ): bool {
		if ( ! $order instanceof WC_Order ) { return false; }
		$tracking = (string) $order->get_meta( '_spx_tracking_number', true );
		$status = (string) $order->get_meta( '_spx_shipment_status', true );
		return SPX_Environment::TEST === SPX_Order_Environment::get( $order )
			&& SPX_Tracking_Service::is_real_tracking( $tracking )
			&& ! in_array( $status, array( 'delivered', 'damaged', 'lost', 'returned', 'cancelled' ), true );
	}
}
