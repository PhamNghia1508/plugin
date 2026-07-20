<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read model over the normalised address dataset. Callers never learn whether
 * storage is a JSON file or a table. Name matching is strict first (normalize),
 * then a UNIQUE loose fallback; multiple candidates return "ambiguous" and are
 * never auto-picked.
 */
final class SPX_Address_Repository {
	/** @var array|null */ private $data;
	/** @var array */      private $prov_idx = array();
	/** @var array */      private $dist_idx = array(); // pkey => index
	/** @var array */      private $ward_idx = array(); // dkey => index

	public function __construct( array $dataset = null ) {
		$this->data = $dataset; // null => lazy load from file
	}

	public static function from_array( array $dataset ): SPX_Address_Repository {
		return new self( $dataset );
	}

	private function data(): array {
		if ( null === $this->data ) {
			$this->data = self::load_from_file();
		}
		return $this->data;
	}

	private static function load_from_file(): array {
		$path = SPX_Address_Import_Service::dataset_path();
		if ( '' === $path || ! is_readable( $path ) ) { return array( 'provinces' => array(), 'meta' => array() ); }
		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $json ) || ! isset( $json['provinces'] ) ) { return array( 'provinces' => array(), 'meta' => array() ); }
		return $json;
	}

	public function is_available(): bool {
		$d = $this->data();
		return ! empty( $d['provinces'] );
	}

	public function get_dataset_metadata(): array {
		$d = $this->data();
		return isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array();
	}

	/** @return array<int,array{code:string,name:string}> */
	public function get_provinces(): array {
		$out = array();
		foreach ( $this->data()['provinces'] as $p ) { $out[] = array( 'code' => $p['code'], 'name' => $p['name'] ); }
		usort( $out, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return $out;
	}

	/** @return array<int,array{code:string,name:string}> */
	public function get_districts( string $province_code ): array {
		$p = $this->province( $province_code );
		if ( null === $p ) { return array(); }
		$out = array();
		foreach ( $p['districts'] as $d ) { $out[] = array( 'code' => $d['code'], 'name' => $d['name'] ); }
		usort( $out, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return $out;
	}

	/** @return array<int,array> ward rows with capability flags */
	public function get_wards( string $district_code ): array {
		$d = $this->district( $district_code );
		if ( null === $d ) { return array(); }
		$out = array_values( $d['wards'] );
		usort( $out, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return $out;
	}

	public function get_ward( string $district_code, string $ward_code ): ?array {
		$d = $this->district( $district_code );
		return ( $d && isset( $d['wards'][ $ward_code ] ) ) ? $d['wards'][ $ward_code ] : null;
	}

	public function validate_hierarchy( string $province_code, string $district_code, string $ward_code ): bool {
		$p = $this->province( $province_code );
		if ( null === $p || ! isset( $p['districts'][ $district_code ] ) ) { return false; }
		return isset( $p['districts'][ $district_code ]['wards'][ $ward_code ] );
	}

	/* ---- name matching --------------------------------------------------- */

	/** @return array{status:string,code?:string,name?:string} */
	public function find_province_by_name( string $name ): array {
		$this->build_prov_idx();
		return $this->match( $this->prov_idx, $name );
	}

	public function find_district_by_name( string $province_code, string $name ): array {
		$this->build_dist_idx( $province_code );
		$idx = isset( $this->dist_idx[ $province_code ] ) ? $this->dist_idx[ $province_code ] : array( 'norm' => array(), 'loose' => array(), 'names' => array() );
		return $this->match( $idx, $name );
	}

	public function find_ward_by_name( string $district_code, string $name ): array {
		$this->build_ward_idx( $district_code );
		$idx = isset( $this->ward_idx[ $district_code ] ) ? $this->ward_idx[ $district_code ] : array( 'norm' => array(), 'loose' => array(), 'names' => array() );
		return $this->match( $idx, $name );
	}

	/* ---- internals ------------------------------------------------------- */

	private function province( string $code ): ?array {
		$provinces = $this->data()['provinces'];
		return isset( $provinces[ $code ] ) ? $provinces[ $code ] : null;
	}

	private function district( string $code ): ?array {
		foreach ( $this->data()['provinces'] as $p ) {
			if ( isset( $p['districts'][ $code ] ) ) { return $p['districts'][ $code ]; }
		}
		return null;
	}

	private function match( array $idx, string $name ): array {
		$n = SPX_Address_Normalizer::normalize( $name );
		if ( '' !== $n && isset( $idx['norm'][ $n ] ) ) {
			$codes = $idx['norm'][ $n ];
			if ( 1 === count( $codes ) ) { return array( 'status' => 'matched', 'code' => $codes[0], 'name' => $idx['names'][ $codes[0] ] ); }
			return array( 'status' => 'ambiguous' );
		}
		$l = SPX_Address_Normalizer::loose_key( $name );
		if ( '' !== $l && isset( $idx['loose'][ $l ] ) ) {
			$codes = array_values( array_unique( $idx['loose'][ $l ] ) );
			if ( 1 === count( $codes ) ) { return array( 'status' => 'matched', 'code' => $codes[0], 'name' => $idx['names'][ $codes[0] ] ); }
			return array( 'status' => 'ambiguous' );
		}
		return array( 'status' => 'none' );
	}

	private function build_prov_idx(): void {
		if ( ! empty( $this->prov_idx ) ) { return; }
		$idx = array( 'norm' => array(), 'loose' => array(), 'names' => array() );
		foreach ( $this->data()['provinces'] as $p ) {
			self::index_add( $idx, $p['code'], $p['name'] );
		}
		$this->prov_idx = $idx;
	}

	private function build_dist_idx( string $province_code ): void {
		if ( isset( $this->dist_idx[ $province_code ] ) ) { return; }
		$p   = $this->province( $province_code );
		$idx = array( 'norm' => array(), 'loose' => array(), 'names' => array() );
		if ( $p ) { foreach ( $p['districts'] as $d ) { self::index_add( $idx, $d['code'], $d['name'] ); } }
		$this->dist_idx[ $province_code ] = $idx;
	}

	private function build_ward_idx( string $district_code ): void {
		if ( isset( $this->ward_idx[ $district_code ] ) ) { return; }
		$d   = $this->district( $district_code );
		$idx = array( 'norm' => array(), 'loose' => array(), 'names' => array() );
		if ( $d ) { foreach ( $d['wards'] as $w ) { self::index_add( $idx, $w['code'], $w['name'] ); } }
		$this->ward_idx[ $district_code ] = $idx;
	}

	private static function index_add( array &$idx, string $code, string $name ): void {
		$n = SPX_Address_Normalizer::normalize( $name );
		$l = SPX_Address_Normalizer::loose_key( $name );
		$idx['norm'][ $n ][]  = $code;
		$idx['loose'][ $l ][] = $code;
		$idx['names'][ $code ] = $name;
	}
}
