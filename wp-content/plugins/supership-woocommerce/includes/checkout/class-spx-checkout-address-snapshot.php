<?php
defined( 'ABSPATH' ) || exit;

/** HPOS-safe canonical checkout selection stored separately from admin override. */
final class SPX_Checkout_Address_Snapshot {
	const SOURCE_CLASSIC_CHECKOUT = 'classic_checkout';
	const SOURCE_BLOCKS_CHECKOUT = 'blocks_checkout';
	const SOURCE_ADMIN_OVERRIDE = 'admin_override';
	const SOURCE_RESOLVER = 'resolver';
	const SOURCE_MIGRATION = 'migration';

	const M_PROVINCE_ID = '_spx_shipping_province_id';
	const M_PROVINCE_NAME = '_spx_shipping_province_name';
	const M_DISTRICT_ID = '_spx_shipping_district_id';
	const M_DISTRICT_NAME = '_spx_shipping_district_name';
	const M_WARD_ID = '_spx_shipping_ward_id';
	const M_WARD_NAME = '_spx_shipping_ward_name';
	const M_SOURCE = '_spx_shipping_address_source';
	const M_DATASET_VERSION = '_spx_shipping_dataset_version';
	const M_DELIVERY = '_spx_shipping_delivery_supported';
	const M_COD = '_spx_shipping_cod_supported';
	const M_VALIDATED_AT = '_spx_shipping_validated_at';

	public static function read_from_order( WC_Order $order ): array {
		return array(
			'province_id' => (string) $order->get_meta( self::M_PROVINCE_ID, true ),
			'province_name' => (string) $order->get_meta( self::M_PROVINCE_NAME, true ),
			'district_id' => (string) $order->get_meta( self::M_DISTRICT_ID, true ),
			'district_name' => (string) $order->get_meta( self::M_DISTRICT_NAME, true ),
			'ward_id' => (string) $order->get_meta( self::M_WARD_ID, true ),
			'ward_name' => (string) $order->get_meta( self::M_WARD_NAME, true ),
			'source' => (string) $order->get_meta( self::M_SOURCE, true ),
			'dataset_version' => (string) $order->get_meta( self::M_DATASET_VERSION, true ),
			'delivery_supported' => '1' === (string) $order->get_meta( self::M_DELIVERY, true ),
			'cod_supported' => '1' === (string) $order->get_meta( self::M_COD, true ),
			'validated_at' => (string) $order->get_meta( self::M_VALIDATED_AT, true ),
		);
	}

	/** Update the in-memory order only; the owning checkout lifecycle performs save(). */
	public static function write_to_order( WC_Order $order, array $snapshot, string $source ): void {
		if ( ! self::is_canonical_source( $source ) ) {
			throw new InvalidArgumentException( 'Invalid SPX shipping address source.' );
		}
		$map = array(
			self::M_PROVINCE_ID => (string) $snapshot['province_id'],
			self::M_PROVINCE_NAME => (string) $snapshot['province_name'],
			self::M_DISTRICT_ID => (string) $snapshot['district_id'],
			self::M_DISTRICT_NAME => (string) $snapshot['district_name'],
			self::M_WARD_ID => (string) $snapshot['ward_id'],
			self::M_WARD_NAME => (string) $snapshot['ward_name'],
			self::M_SOURCE => $source,
			self::M_DATASET_VERSION => (string) $snapshot['dataset_version'],
			self::M_DELIVERY => ! empty( $snapshot['delivery_supported'] ) ? '1' : '0',
			self::M_COD => ! empty( $snapshot['cod_supported'] ) ? '1' : '0',
			self::M_VALIDATED_AT => (string) $snapshot['validated_at'],
		);
		foreach ( $map as $key => $value ) { $order->update_meta_data( $key, $value ); }
	}

	public static function is_canonical_source( string $source ): bool {
		return in_array( $source, array(
			self::SOURCE_CLASSIC_CHECKOUT,
			self::SOURCE_BLOCKS_CHECKOUT,
			self::SOURCE_ADMIN_OVERRIDE,
			self::SOURCE_RESOLVER,
			self::SOURCE_MIGRATION,
		), true );
	}

	public static function is_checkout_source( string $source ): bool {
		return in_array( $source, array(
			self::SOURCE_CLASSIC_CHECKOUT,
			self::SOURCE_BLOCKS_CHECKOUT,
			'checkout',
			'checkout_snapshot',
		), true );
	}

	public static function source_label( string $source ): string {
		$labels = array(
			self::SOURCE_CLASSIC_CHECKOUT => __( 'Checkout Classic', 'spx-express-woocommerce' ),
			self::SOURCE_BLOCKS_CHECKOUT  => __( 'Checkout Blocks', 'spx-express-woocommerce' ),
			self::SOURCE_ADMIN_OVERRIDE   => __( 'Quản trị viên ghi đè', 'spx-express-woocommerce' ),
			self::SOURCE_RESOLVER         => __( 'Tự phân giải', 'spx-express-woocommerce' ),
			self::SOURCE_MIGRATION        => __( 'Dữ liệu chuyển đổi', 'spx-express-woocommerce' ),
			'checkout'                    => __( 'Checkout — nguồn cũ không xác định', 'spx-express-woocommerce' ),
			'checkout_snapshot'           => __( 'Checkout — nguồn cũ không xác định', 'spx-express-woocommerce' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Chưa xác định', 'spx-express-woocommerce' );
	}

	public static function is_complete( array $snapshot ): bool {
		return '' !== $snapshot['province_id'] && '' !== $snapshot['district_id'] && '' !== $snapshot['ward_id'] && '' !== $snapshot['dataset_version'];
	}

	public static function is_current_and_valid( WC_Order $order, SPX_Checkout_Address_Service $service ): bool {
		$snapshot = self::read_from_order( $order );
		if ( ! self::is_complete( $snapshot ) ) { return false; }
		$result = $service->validate_selection( $snapshot['province_id'], $snapshot['district_id'], $snapshot['ward_id'], false, $snapshot['dataset_version'] );
		return ! empty( $result['valid'] );
	}
}
