<?php
defined( 'ABSPATH' ) || exit;

/** Raised on any unsafe or malformed workbook. */
final class SPX_XLSX_Exception extends RuntimeException {}

/**
 * Minimal, dependency-free streaming reader for the SPX service-area .xlsx.
 * Reads one worksheet row at a time (XMLReader + per-row SimpleXML) so a
 * 10k-row / ~8 MB sheet never loads as a whole DOM. Hardened against zip
 * bombs, oversized entries, path traversal, and non-zip payloads.
 *
 * Cell value resolution supports the real file's `t="str"` + numeric cells,
 * plus shared strings (`t="s"`) and inline strings (`t="inlineStr"`) for
 * forward compatibility with other SPX exports.
 */
final class SPX_XLSX_Reader {
	const MAX_ENTRY_BYTES = 80 * 1024 * 1024; // per-entry uncompressed guard
	const MAX_ROWS        = 200000;           // hard row cap

	/** @var string[] */ private $shared = array();
	/** @var string */   private $sheet_xml;

	private function __construct( string $sheet_xml, array $shared ) {
		$this->sheet_xml = $sheet_xml;
		$this->shared    = $shared;
	}

	public static function open( string $path ): SPX_XLSX_Reader {
		if ( ! is_readable( $path ) ) {
			throw new SPX_XLSX_Exception( 'Address file is not readable.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new SPX_XLSX_Exception( 'The PHP zip extension is required to read .xlsx files.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new SPX_XLSX_Exception( 'The address file is not a valid .xlsx (zip) file.' );
		}

		// Zip-bomb / traversal guards.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = (string) $stat['name'];
			if ( false !== strpos( $name, '..' ) || '/' === substr( $name, 0, 1 ) || preg_match( '#^[a-zA-Z]:#', $name ) ) {
				$zip->close();
				throw new SPX_XLSX_Exception( 'The address file contains an unsafe entry path.' );
			}
			if ( (int) $stat['size'] > self::MAX_ENTRY_BYTES ) {
				$zip->close();
				throw new SPX_XLSX_Exception( 'The address file has an oversized internal entry.' );
			}
		}

		$sheet_path = self::first_sheet_path( $zip );
		$sheet_xml  = $zip->getFromName( $sheet_path );
		if ( false === $sheet_xml || '' === $sheet_xml ) {
			$zip->close();
			throw new SPX_XLSX_Exception( 'The address workbook has no readable worksheet.' );
		}

		$shared     = array();
		$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( is_string( $shared_xml ) && '' !== $shared_xml ) {
			$sx = simplexml_load_string( $shared_xml );
			if ( $sx ) {
				foreach ( $sx->si as $si ) {
					$text = '';
					foreach ( $si->xpath( './/*[local-name()="t"]' ) as $t ) { $text .= (string) $t; }
					$shared[] = $text;
				}
			}
		}
		$zip->close();

		return new self( $sheet_xml, $shared );
	}

	/** Resolve the first worksheet part; fall back to the conventional path. */
	private static function first_sheet_path( ZipArchive $zip ): string {
		$default = 'xl/worksheets/sheet1.xml';
		$wb      = $zip->getFromName( 'xl/workbook.xml' );
		$rels    = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( ! is_string( $wb ) || ! is_string( $rels ) ) { return $default; }
		$wx = simplexml_load_string( $wb );
		$rx = simplexml_load_string( $rels );
		if ( ! $wx || ! $rx ) { return $default; }
		$sheets = $wx->xpath( '//*[local-name()="sheet"]' );
		if ( ! $sheets ) { return $default; }
		$rid = '';
		foreach ( $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' ) as $k => $v ) {
			if ( 'id' === $k ) { $rid = (string) $v; }
		}
		if ( '' === $rid ) { return $default; }
		foreach ( $rx->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				if ( 0 !== strpos( $target, 'xl/' ) ) { $target = 'xl/' . $target; }
				return $target;
			}
		}
		return $default;
	}

	/**
	 * Invoke $callback( array $cells_by_column_letter, int $row_number ) for each row.
	 * Streaming: only one row is held in memory at a time.
	 */
	public function each_row( callable $callback ): void {
		$reader = new XMLReader();
		if ( false === $reader->XML( $this->sheet_xml, 'UTF-8', LIBXML_NONET ) ) {
			throw new SPX_XLSX_Exception( 'The worksheet XML could not be parsed.' );
		}
		$seen = 0;
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) { continue; }
			if ( ++$seen > self::MAX_ROWS ) {
				$reader->close();
				throw new SPX_XLSX_Exception( 'The address file exceeds the maximum supported row count.' );
			}
			$rownum  = (int) $reader->getAttribute( 'r' );
			$row_xml = $reader->readOuterXml();
			$callback( $this->parse_row( $row_xml ), $rownum > 0 ? $rownum : $seen );
		}
		$reader->close();
	}

	/** @return array<string,string> column-letter => value */
	private function parse_row( string $row_xml ): array {
		// Drop any default namespace so plain SimpleXML child access works.
		$row_xml = preg_replace( '/\sxmlns="[^"]*"/', '', $row_xml, 1 );
		$sx      = simplexml_load_string( $row_xml, 'SimpleXMLElement', LIBXML_NONET );
		$cells   = array();
		if ( ! $sx ) { return $cells; }
		foreach ( $sx->c as $c ) {
			$ref     = (string) $c['r'];
			$letters = rtrim( preg_replace( '/[0-9]+/', '', $ref ), '' );
			if ( '' === $letters ) { continue; }
			$type = (string) $c['t'];
			if ( 's' === $type ) {
				$idx   = isset( $c->v ) ? (int) $c->v : -1;
				$value = isset( $this->shared[ $idx ] ) ? $this->shared[ $idx ] : '';
			} elseif ( 'inlineStr' === $type ) {
				$value = isset( $c->is->t ) ? (string) $c->is->t : '';
			} else { // 'str' or numeric
				$value = isset( $c->v ) ? (string) $c->v : '';
			}
			$cells[ $letters ] = $value;
		}
		return $cells;
	}
}
