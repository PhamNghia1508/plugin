<?php
defined( 'ABSPATH' ) || exit;

/** Canonical local-dataset boundary for all checkout address consumers. */
final class SPX_Checkout_Address_Service {
	/** @var SPX_Address_Repository */
	private $repo;

	public function __construct( SPX_Address_Repository $repo = null ) {
		$this->repo = $repo ?: new SPX_Address_Repository();
	}

	public function get_dataset_version(): string {
		$meta = $this->repo->get_dataset_metadata();
		return isset( $meta['version'] ) ? sanitize_text_field( (string) $meta['version'] ) : '';
	}

	/** @return array<int,array{id:string,label:string}> */
	public function get_provinces(): array {
		$out = array();
		foreach ( $this->repo->get_provinces() as $row ) {
			$out[] = array( 'id' => (string) $row['code'], 'label' => (string) $row['name'] );
		}
		return $out;
	}

	/** @return array<int,array{id:string,label:string}> */
	public function get_districts( string $province_id ): array {
		if ( ! self::valid_parent_id( $province_id ) ) { return array(); }
		$out = array();
		foreach ( $this->repo->get_districts( $province_id ) as $row ) {
			$out[] = array( 'id' => (string) $row['code'], 'label' => (string) $row['name'] );
		}
		return $out;
	}

	/** @return array<int,array{id:string,label:string,active:bool,delivery_supported:bool,cod_supported:bool}> */
	public function get_wards( string $province_id, string $district_id ): array {
		if ( ! self::valid_parent_id( $province_id ) || ! self::valid_parent_id( $district_id ) || ! $this->district_belongs_to_province( $province_id, $district_id ) ) { return array(); }
		$out = array();
		foreach ( $this->repo->get_wards( $district_id ) as $row ) {
			$out[] = array(
				'id'                 => (string) $row['code'],
				'label'              => (string) $row['name'],
				'active'             => 'available' === strtolower( trim( (string) $row['status'] ) ),
				'delivery_supported' => ! empty( $row['delivery'] ),
				'cod_supported'      => ! empty( $row['cod'] ),
			);
		}
		return $out;
	}

	/** @return array{valid:bool,error:string,snapshot?:array} */
	public function validate_selection( string $province_id, string $district_id, string $ward_id, bool $is_cod, string $submitted_version = '' ): array {
		$current_version = $this->get_dataset_version();
		if ( '' !== $submitted_version && ! hash_equals( $current_version, $submitted_version ) ) { return self::failure( 'dataset_version_mismatch' ); }
		if ( ! self::valid_parent_id( $province_id ) || ! self::valid_parent_id( $district_id ) || ! self::valid_ward_id( $ward_id ) || ! $this->repo->validate_hierarchy( $province_id, $district_id, $ward_id ) ) {
			return self::failure( 'hierarchy_invalid' );
		}
		$ward = $this->repo->get_ward( $district_id, $ward_id );
		if ( ! $ward ) { return self::failure( 'hierarchy_invalid' ); }
		if ( 'available' !== strtolower( trim( (string) $ward['status'] ) ) ) { return self::failure( 'ward_unavailable' ); }
		if ( empty( $ward['delivery'] ) ) { return self::failure( 'delivery_unsupported' ); }
		if ( $is_cod && empty( $ward['cod'] ) ) { return self::failure( 'cod_unsupported' ); }

		$province_name = $this->label_for( $this->get_provinces(), $province_id );
		$district_name = $this->label_for( $this->get_districts( $province_id ), $district_id );
		return array(
			'valid'    => true,
			'error'    => '',
			'snapshot' => array(
				'province_id'       => $province_id,
				'province_name'     => $province_name,
				'district_id'       => $district_id,
				'district_name'     => $district_name,
				'ward_id'           => $ward_id,
				'ward_name'         => (string) $ward['name'],
				'dataset_version'   => $current_version,
				'delivery_supported'=> true,
				'cod_supported'     => ! empty( $ward['cod'] ),
				'validated_at'      => gmdate( 'c' ),
			),
		);
	}

	private function district_belongs_to_province( string $province_id, string $district_id ): bool {
		foreach ( $this->repo->get_districts( $province_id ) as $row ) {
			if ( hash_equals( (string) $row['code'], $district_id ) ) { return true; }
		}
		return false;
	}

	private function label_for( array $rows, string $id ): string {
		foreach ( $rows as $row ) { if ( hash_equals( (string) $row['id'], $id ) ) { return (string) $row['label']; } }
		return '';
	}

	private static function valid_parent_id( string $id ): bool { return 1 === preg_match( '/^[pd]_[a-f0-9]{12}$/', $id ); }
	private static function valid_ward_id( string $id ): bool { return 1 === preg_match( '/^[1-9][0-9]{0,15}$/', $id ); }
	private static function failure( string $error ): array { return array( 'valid' => false, 'error' => $error ); }
}
