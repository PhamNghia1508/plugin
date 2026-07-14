<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order-level SPX recipient address override, stored via WC_Order CRUD (HPOS-safe).
 * It never changes the customer's WooCommerce address — it only records the SPX
 * province/district/ward mapping used for shipping. Province/District have no
 * official SPX code, so our deterministic dataset keys are stored; the ward code
 * is the SPX "Sort Codet ID". Display names are snapshotted (prefix `_spx_`) so a
 * later dataset version cannot orphan the label.
 */
final class SPX_Order_Address {
	const M_PROVINCE_CODE = '_spx_recipient_province_code';
	const M_DISTRICT_CODE = '_spx_recipient_district_code';
	const M_WARD_CODE     = '_spx_recipient_ward_code';
	const M_PROVINCE_NAME = '_spx_recipient_province_name';
	const M_DISTRICT_NAME = '_spx_recipient_district_name';
	const M_WARD_NAME     = '_spx_recipient_ward_name';
	const M_RESOLVED_AT   = '_spx_recipient_address_resolved_at';
	const M_SOURCE        = '_spx_recipient_address_source';

	public static function get( WC_Order $order ): array {
		return array(
			'province_code' => (string) $order->get_meta( self::M_PROVINCE_CODE, true ),
			'district_code' => (string) $order->get_meta( self::M_DISTRICT_CODE, true ),
			'ward_code'     => (string) $order->get_meta( self::M_WARD_CODE, true ),
			'province_name' => (string) $order->get_meta( self::M_PROVINCE_NAME, true ),
			'district_name' => (string) $order->get_meta( self::M_DISTRICT_NAME, true ),
			'ward_name'     => (string) $order->get_meta( self::M_WARD_NAME, true ),
			'resolved_at'   => (string) $order->get_meta( self::M_RESOLVED_AT, true ),
			'source'        => (string) $order->get_meta( self::M_SOURCE, true ),
		);
	}

	public static function has_override( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::M_PROVINCE_CODE, true )
			&& '' !== (string) $order->get_meta( self::M_DISTRICT_CODE, true )
			&& '' !== (string) $order->get_meta( self::M_WARD_CODE, true );
	}

	/**
	 * Validate against the dataset and persist. Rejects codes that do not form a
	 * valid hierarchy. @return array{ok:bool,error:string}
	 */
	public static function save( WC_Order $order, string $pcode, string $dcode, string $wcode, SPX_Address_Repository $repo, string $source = 'order_override' ): array {
		$pcode = sanitize_text_field( $pcode );
		$dcode = sanitize_text_field( $dcode );
		$wcode = sanitize_text_field( $wcode );
		if ( '' === $pcode && '' === $dcode && '' === $wcode ) {
			self::clear( $order );
			return array( 'ok' => true, 'error' => '' );
		}
		if ( ! $repo->is_available() || ! $repo->validate_hierarchy( $pcode, $dcode, $wcode ) ) {
			return array( 'ok' => false, 'error' => __( 'The selected SPX province/district/ward is not valid.', 'spx-express-woocommerce' ) );
		}
		$ward   = $repo->get_ward( $dcode, $wcode );
		$pname  = '';
		$dname  = '';
		foreach ( $repo->get_provinces() as $p ) { if ( $p['code'] === $pcode ) { $pname = $p['name']; } }
		foreach ( $repo->get_districts( $pcode ) as $d ) { if ( $d['code'] === $dcode ) { $dname = $d['name']; } }

		$order->update_meta_data( self::M_PROVINCE_CODE, $pcode );
		$order->update_meta_data( self::M_DISTRICT_CODE, $dcode );
		$order->update_meta_data( self::M_WARD_CODE, $wcode );
		$order->update_meta_data( self::M_PROVINCE_NAME, $pname );
		$order->update_meta_data( self::M_DISTRICT_NAME, $dname );
		$order->update_meta_data( self::M_WARD_NAME, $ward ? $ward['name'] : '' );
		$order->update_meta_data( self::M_RESOLVED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::M_SOURCE, sanitize_key( $source ) );
		$order->save();
		return array( 'ok' => true, 'error' => '' );
	}

	public static function clear( WC_Order $order ): void {
		foreach ( array( self::M_PROVINCE_CODE, self::M_DISTRICT_CODE, self::M_WARD_CODE, self::M_PROVINCE_NAME, self::M_DISTRICT_NAME, self::M_WARD_NAME, self::M_RESOLVED_AT, self::M_SOURCE ) as $k ) {
			$order->delete_meta_data( $k );
		}
		$order->save();
	}
}
