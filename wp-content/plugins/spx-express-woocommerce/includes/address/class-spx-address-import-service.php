<?php
defined( 'ABSPATH' ) || exit;

/**
 * Imports the SPX service-area .xlsx into a normalised address dataset and stores
 * it atomically. Validation is all-or-nothing: if any row fails, the previous
 * dataset is left untouched (rollback). The API/download sync path is kept for
 * the future (see build_from_download_url) but this phase imports a LOCAL file.
 */
final class SPX_Address_Import_Service {
	const PARSER_VERSION = 1;
	const DATASET_FILE   = 'spx-vn-addresses.json';
	const META_OPTION    = 'spx_address_dataset_meta';
	const SUBDIR         = 'spx-express';
	const MAX_ERRORS     = 50; // stop collecting after this many, still fail

	/** Canonical field => accepted header labels (first is the real file's header). */
	private static function header_aliases(): array {
		return array(
			'id'       => array( 'Sort Codet ID', 'Sort Code ID' ),
			'ward'     => array( 'Sort Code' ),
			'district' => array( 'District' ),
			'province' => array( 'Province' ),
			'delivery' => array( 'Delivery' ),
			'pickup'   => array( 'Pick Up', 'Pickup' ),
			'cod'      => array( 'COD' ),
			'status'   => array( 'Status' ),
		);
	}

	/**
	 * Parse + validate + build from a local .xlsx. No files are written.
	 * @return array{ok:bool,errors:array,dataset:?array,meta:array}
	 */
	public function build_from_file( string $path ): array {
		try {
			$reader = SPX_XLSX_Reader::open( $path );
		} catch ( SPX_XLSX_Exception $e ) {
			return array( 'ok' => false, 'errors' => array( array( 'code' => 'file_unreadable', 'message' => $e->getMessage() ) ), 'dataset' => null, 'meta' => array() );
		}

		$col   = array();  // canonical field => column letter
		$rows  = array();
		$error = null;
		$reader->each_row( function ( $cells, $rownum ) use ( &$col, &$rows, &$error ) {
			if ( null !== $error ) { return; }
			if ( 1 === $rownum ) {
				$col = self::map_header( $cells );
				if ( is_string( $col ) ) { $error = $col; $col = array(); }
				return;
			}
			if ( empty( $col ) ) { return; }
			$row = array();
			foreach ( $col as $field => $letter ) { $row[ $field ] = isset( $cells[ $letter ] ) ? trim( (string) $cells[ $letter ] ) : ''; }
			// Skip a fully blank row (trailing).
			if ( '' === $row['id'] && '' === $row['ward'] && '' === $row['province'] ) { return; }
			$rows[] = $row;
		} );

		if ( null !== $error ) {
			return array( 'ok' => false, 'errors' => array( array( 'code' => 'bad_header', 'message' => $error ) ), 'dataset' => null, 'meta' => array() );
		}

		$result             = $this->validate_and_build( $rows );
		$result['meta']    += array(
			'source'         => basename( $path ),
			'environment'    => 'file',
			'version'        => self::derive_version( $path ),
			'imported_at'    => gmdate( 'c' ),
			'checksum'       => is_readable( $path ) ? hash_file( 'sha256', $path ) : '',
			'parser_version' => self::PARSER_VERSION,
		);
		return $result;
	}

	/**
	 * Pure validation + dataset construction from canonical rows. Unit-testable
	 * without any .xlsx. All-or-nothing: ok=false if any row is invalid.
	 * @param array<int,array<string,string>> $rows
	 * @return array{ok:bool,errors:array,dataset:?array,meta:array}
	 */
	public function validate_and_build( array $rows ): array {
		$errors    = array();
		$seen_ids  = array();
		$provinces = array();
		$dcount    = array();
		$ward_n    = 0;

		foreach ( $rows as $i => $row ) {
			$line = $i + 2; // human row number (header is row 1)
			$id   = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			$ward = isset( $row['ward'] ) ? trim( (string) $row['ward'] ) : '';
			$dist = isset( $row['district'] ) ? trim( (string) $row['district'] ) : '';
			$prov = isset( $row['province'] ) ? trim( (string) $row['province'] ) : '';
			$del  = strtoupper( isset( $row['delivery'] ) ? trim( (string) $row['delivery'] ) : '' );
			$pick = strtoupper( isset( $row['pickup'] ) ? trim( (string) $row['pickup'] ) : '' );
			$cod  = strtoupper( isset( $row['cod'] ) ? trim( (string) $row['cod'] ) : '' );
			$stat = isset( $row['status'] ) ? trim( (string) $row['status'] ) : '';

			if ( ! preg_match( '/^\d+$/', $id ) || (int) $id <= 0 ) { $errors[] = self::err( 'invalid_id', $line, 'Sort Codet ID must be a positive integer.' ); }
			elseif ( isset( $seen_ids[ $id ] ) ) { $errors[] = self::err( 'duplicate_id', $line, 'Duplicate Sort Codet ID: ' . $id ); }
			if ( '' === $ward ) { $errors[] = self::err( 'ward_missing', $line, 'Ward (Sort Code) is empty.' ); }
			if ( '' === $dist ) { $errors[] = self::err( 'district_missing', $line, 'District is empty.' ); }
			if ( '' === $prov ) { $errors[] = self::err( 'province_missing', $line, 'Province is empty.' ); }
			if ( ! in_array( $del, array( 'Y', 'N' ), true ) ) { $errors[] = self::err( 'invalid_delivery', $line, 'Delivery must be Y or N.' ); }
			if ( ! in_array( $pick, array( 'Y', 'N' ), true ) ) { $errors[] = self::err( 'invalid_pickup', $line, 'Pick Up must be Y or N.' ); }
			if ( ! in_array( $cod, array( 'Y', 'N' ), true ) ) { $errors[] = self::err( 'invalid_cod', $line, 'COD must be Y or N.' ); }
			if ( '' === $stat ) { $errors[] = self::err( 'status_missing', $line, 'Status is empty.' ); }

			if ( count( $errors ) >= self::MAX_ERRORS ) { break; }
			if ( ! empty( $errors ) ) { continue; } // once failing, keep scanning but don't build

			$seen_ids[ $id ] = true;
			$pkey            = self::pkey( $prov );
			$dkey            = self::dkey( $pkey, $dist );
			if ( ! isset( $provinces[ $pkey ] ) ) {
				$provinces[ $pkey ] = array( 'code' => $pkey, 'name' => $prov, 'norm' => SPX_Address_Normalizer::normalize( $prov ), 'districts' => array() );
			}
			if ( ! isset( $provinces[ $pkey ]['districts'][ $dkey ] ) ) {
				$provinces[ $pkey ]['districts'][ $dkey ] = array( 'code' => $dkey, 'name' => $dist, 'norm' => SPX_Address_Normalizer::normalize( $dist ), 'wards' => array() );
				$dcount[ $dkey ] = true;
			}
			$provinces[ $pkey ]['districts'][ $dkey ]['wards'][ $id ] = array(
				'code'     => $id,
				'name'     => $ward,
				'norm'     => SPX_Address_Normalizer::normalize( $ward ),
				'delivery' => ( 'Y' === $del ),
				'pickup'   => ( 'Y' === $pick ),
				'cod'      => ( 'Y' === $cod ),
				'status'   => $stat,
			);
			$ward_n++;
		}

		$ok = empty( $errors );
		return array(
			'ok'      => $ok,
			'errors'  => $errors,
			'dataset' => $ok ? array( 'provinces' => $provinces ) : null,
			'meta'    => array(
				'record_count'   => $ward_n,
				'province_count' => count( $provinces ),
				'district_count' => count( $dcount ),
				'ward_count'     => $ward_n,
			),
		);
	}

	/** Atomically import a local file, replacing the dataset only on full success. */
	public function import_from_file( string $path ): array {
		$built = $this->build_from_file( $path );
		if ( ! $built['ok'] || null === $built['dataset'] ) { return $built; }
		$stored = $this->store( $built['dataset'], $built['meta'] );
		if ( ! $stored ) {
			$built['ok']       = false;
			$built['errors'][] = self::err( 'store_failed', 0, 'Could not write the dataset file; the previous dataset is unchanged.' );
		}
		return $built;
	}

	/** Atomic write: temp file in the same dir, then rename over the target. */
	public function store( array $dataset, array $meta ): bool {
		$dir = self::dataset_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) { return false; }
		$payload = wp_json_encode( array( 'meta' => $meta, 'provinces' => $dataset['provinces'] ) );
		if ( false === $payload ) { return false; }
		$target = trailingslashit( $dir ) . self::DATASET_FILE;
		$tmp    = $target . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $payload, LOCK_EX ) ) { return false; }
		if ( ! @rename( $tmp, $target ) ) { @unlink( $tmp ); return false; }
		update_option( self::META_OPTION, $meta, false ); // autoload = false
		return true;
	}

	public static function dataset_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) { return ''; }
		$up = wp_upload_dir();
		if ( empty( $up['basedir'] ) ) { return ''; }
		return trailingslashit( $up['basedir'] ) . self::SUBDIR;
	}

	public static function dataset_path(): string {
		$dir = self::dataset_dir();
		return '' === $dir ? '' : trailingslashit( $dir ) . self::DATASET_FILE;
	}

	private static function map_header( array $header_cells ) {
		$by_label = array();
		foreach ( $header_cells as $letter => $label ) { $by_label[ trim( (string) $label ) ] = $letter; }
		$map = array();
		foreach ( self::header_aliases() as $field => $labels ) {
			$found = '';
			foreach ( $labels as $label ) { if ( isset( $by_label[ $label ] ) ) { $found = $by_label[ $label ]; break; } }
			if ( '' === $found ) { return 'Missing required column: ' . $labels[0]; }
			$map[ $field ] = $found;
		}
		return $map;
	}

	private static function pkey( string $province ): string {
		return 'p_' . substr( md5( SPX_Address_Normalizer::normalize( $province ) ), 0, 12 );
	}

	private static function dkey( string $pkey, string $district ): string {
		return 'd_' . substr( md5( $pkey . '|' . SPX_Address_Normalizer::normalize( $district ) ), 0, 12 );
	}

	private static function derive_version( string $path ): string {
		if ( preg_match( '/(\d{8})/', basename( $path ), $m ) ) { return $m[1]; }
		return is_readable( $path ) ? (string) filemtime( $path ) : (string) time();
	}

	private static function err( string $code, int $line, string $message ): array {
		return array( 'code' => $code, 'line' => $line, 'message' => $message );
	}
}
