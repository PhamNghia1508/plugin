<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Address Repository
 * 
 * API-based address repository using SuperShip Areas API.
 * 
 * Key differences from SPX:
 * - SPX: Uses XLSX import with numeric codes
 * - SuperShip: Uses live API with string-based names
 * 
 * API endpoints:
 * - GET /v1/partner/areas/province
 * - GET /v1/partner/areas/district?province={code}
 * - GET /v1/partner/areas/commune?district={code}
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Address_Repository {
	
	/** @var SuperShip_Http_Client_Interface */
	private $client;
	
	/** @var SuperShip_Address_Cache */
	private $cache;
	
	/** @var SuperShip_Address_Normalizer */
	private $normalizer;

	/**
	 * @param SuperShip_Http_Client_Interface|null $client HTTP client
	 * @param SuperShip_Address_Cache|null         $cache Cache manager
	 */
	public function __construct(
		SuperShip_Http_Client_Interface $client = null,
		SuperShip_Address_Cache $cache = null
	) {
		$this->client     = $client ?: new SuperShip_HTTP_Client();
		$this->cache      = $cache ?: new SuperShip_Address_Cache();
		$this->normalizer = new SuperShip_Address_Normalizer();
	}

	/**
	 * Get all provinces
	 * 
	 * Returns: [{code, name}, ...]
	 * 
	 * @param bool $force_refresh Skip cache
	 * @return array Array of provinces or empty on error
	 */
	public function get_provinces( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = $this->cache->get_provinces();
			if ( null !== $cached ) {
				return $cached;
			}
		}
		
		$response = $this->client->get( '/v1/partner/areas/province' );
		
		if ( ! $response->is_success() ) {
			return array(); // Failed to fetch
		}
		
		$results = $response->get_results();
		if ( ! is_array( $results ) ) {
			return array();
		}
		
		// Normalize structure: [{code, name}, ...]
		$provinces = array();
		foreach ( $results as $item ) {
			if ( ! isset( $item['code'], $item['name'] ) ) {
				continue;
			}
			$provinces[] = array(
				'code' => (string) $item['code'],
				'name' => (string) $item['name'],
			);
		}
		
		// Cache for 24 hours
		$this->cache->set_provinces( $provinces );
		
		return $provinces;
	}

	/**
	 * Get districts for a province
	 * 
	 * @param string $province_code Province code
	 * @param bool   $force_refresh Skip cache
	 * @return array Array of districts [{code, name, province}, ...]
	 */
	public function get_districts( string $province_code, bool $force_refresh = false ): array {
		if ( '' === $province_code ) {
			return array();
		}
		
		if ( ! $force_refresh ) {
			$cached = $this->cache->get_districts( $province_code );
			if ( null !== $cached ) {
				return $cached;
			}
		}
		
		$response = $this->client->get(
			'/v1/partner/areas/district',
			array( 'province' => $province_code )
		);
		
		if ( ! $response->is_success() ) {
			return array();
		}
		
		$results = $response->get_results();
		if ( ! is_array( $results ) ) {
			return array();
		}
		
		$districts = array();
		foreach ( $results as $item ) {
			if ( ! isset( $item['code'], $item['name'] ) ) {
				continue;
			}
			$districts[] = array(
				'code'     => (string) $item['code'],
				'name'     => (string) $item['name'],
				'province' => isset( $item['province'] ) ? (string) $item['province'] : '',
			);
		}
		
		$this->cache->set_districts( $province_code, $districts );
		
		return $districts;
	}

	/**
	 * Get communes for a district
	 * 
	 * @param string $district_code District code
	 * @param bool   $force_refresh Skip cache
	 * @return array Array of communes [{code, name, district, province}, ...]
	 */
	public function get_communes( string $district_code, bool $force_refresh = false ): array {
		if ( '' === $district_code ) {
			return array();
		}
		
		if ( ! $force_refresh ) {
			$cached = $this->cache->get_communes( $district_code );
			if ( null !== $cached ) {
				return $cached;
			}
		}
		
		$response = $this->client->get(
			'/v1/partner/areas/commune',
			array( 'district' => $district_code )
		);
		
		if ( ! $response->is_success() ) {
			return array();
		}
		
		$results = $response->get_results();
		if ( ! is_array( $results ) ) {
			return array();
		}
		
		$communes = array();
		foreach ( $results as $item ) {
			if ( ! isset( $item['code'], $item['name'] ) ) {
				continue;
			}
			$communes[] = array(
				'code'     => (string) $item['code'],
				'name'     => (string) $item['name'],
				'district' => isset( $item['district'] ) ? (string) $item['district'] : '',
				'province' => isset( $item['province'] ) ? (string) $item['province'] : '',
			);
		}
		
		$this->cache->set_communes( $district_code, $communes );
		
		return $communes;
	}

	/**
	 * Find province by name (fuzzy matching)
	 * 
	 * Returns: {status: matched|ambiguous|none, code?, name?}
	 * 
	 * @param string $name Province name to search
	 * @return array Match result
	 */
	public function find_province_by_name( string $name ): array {
		$provinces = $this->get_provinces();
		
		if ( empty( $provinces ) ) {
			return array( 'status' => 'none' );
		}
		
		return $this->fuzzy_match( $provinces, $name );
	}

	/**
	 * Find district by name within a province
	 * 
	 * @param string $province_code Province code
	 * @param string $name District name to search
	 * @return array Match result
	 */
	public function find_district_by_name( string $province_code, string $name ): array {
		$districts = $this->get_districts( $province_code );
		
		if ( empty( $districts ) ) {
			return array( 'status' => 'none' );
		}
		
		return $this->fuzzy_match( $districts, $name );
	}

	/**
	 * Find commune by name within a district
	 * 
	 * @param string $district_code District code
	 * @param string $name Commune name to search
	 * @return array Match result
	 */
	public function find_commune_by_name( string $district_code, string $name ): array {
		$communes = $this->get_communes( $district_code );
		
		if ( empty( $communes ) ) {
			return array( 'status' => 'none' );
		}
		
		return $this->fuzzy_match( $communes, $name );
	}

	/**
	 * Fuzzy match name against items
	 * 
	 * Strategy:
	 * 1. Try strict normalized match (keeps diacritics)
	 * 2. Fall back to loose match (ASCII-folded, prefix-stripped)
	 * 3. Return ambiguous if multiple matches
	 * 
	 * @param array  $items Items to search
	 * @param string $name Name to find
	 * @return array {status: matched|ambiguous|none, code?, name?}
	 */
	private function fuzzy_match( array $items, string $name ): array {
		$normalized = $this->normalizer::normalize( $name );
		$loose      = $this->normalizer::loose_key( $name );
		
		$norm_matches  = array();
		$loose_matches = array();
		
		foreach ( $items as $item ) {
			$item_norm  = $this->normalizer::normalize( $item['name'] );
			$item_loose = $this->normalizer::loose_key( $item['name'] );
			
			if ( $normalized === $item_norm ) {
				$norm_matches[] = $item;
			} elseif ( $loose === $item_loose ) {
				$loose_matches[] = $item;
			}
		}
		
		// Prefer strict matches
		if ( 1 === count( $norm_matches ) ) {
			return array(
				'status' => 'matched',
				'code'   => $norm_matches[0]['code'],
				'name'   => $norm_matches[0]['name'],
			);
		}
		
		if ( count( $norm_matches ) > 1 ) {
			return array( 'status' => 'ambiguous' );
		}
		
		// Try loose matches
		if ( 1 === count( $loose_matches ) ) {
			return array(
				'status' => 'matched',
				'code'   => $loose_matches[0]['code'],
				'name'   => $loose_matches[0]['name'],
			);
		}
		
		if ( count( $loose_matches ) > 1 ) {
			return array( 'status' => 'ambiguous' );
		}
		
		return array( 'status' => 'none' );
	}

	/**
	 * Check if repository is available (can fetch data)
	 * 
	 * @return bool
	 */
	public function is_available(): bool {
		// Try to fetch provinces
		$provinces = $this->get_provinces();
		return ! empty( $provinces );
	}

	/**
	 * Clear all cached address data
	 * 
	 * @return bool Success
	 */
	public function clear_cache(): bool {
		return $this->cache->clear_all();
	}
}
