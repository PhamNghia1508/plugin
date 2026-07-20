<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Address Cache
 * 
 * Caches SuperShip Areas API responses to minimize API calls.
 * 
 * Cache strategy:
 * - Provinces: 24 hours (rarely change)
 * - Districts: 24 hours per province
 * - Communes: 24 hours per district
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Address_Cache {
	
	const CACHE_GROUP     = 'supership_areas';
	const CACHE_PROVINCES = 'provinces';
	const CACHE_DISTRICTS = 'districts_'; // + province_code
	const CACHE_COMMUNES  = 'communes_';  // + district_code
	const TTL_SECONDS     = 86400; // 24 hours

	/**
	 * Get cached provinces
	 * 
	 * @return array|null Cached provinces or null if not cached
	 */
	public function get_provinces(): ?array {
		$cached = wp_cache_get( self::CACHE_PROVINCES, self::CACHE_GROUP );
		
		if ( false === $cached ) {
			// Try transient fallback (persistent cache)
			$cached = get_transient( $this->transient_key( self::CACHE_PROVINCES ) );
			if ( false === $cached ) {
				return null;
			}
			// Re-populate object cache
			wp_cache_set( self::CACHE_PROVINCES, $cached, self::CACHE_GROUP, self::TTL_SECONDS );
		}
		
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Set provinces cache
	 * 
	 * @param array $provinces Provinces data from SuperShip API
	 * @return bool Success
	 */
	public function set_provinces( array $provinces ): bool {
		wp_cache_set( self::CACHE_PROVINCES, $provinces, self::CACHE_GROUP, self::TTL_SECONDS );
		return set_transient( $this->transient_key( self::CACHE_PROVINCES ), $provinces, self::TTL_SECONDS );
	}

	/**
	 * Get cached districts for a province
	 * 
	 * @param string $province_code Province code
	 * @return array|null Cached districts or null
	 */
	public function get_districts( string $province_code ): ?array {
		$key    = self::CACHE_DISTRICTS . $province_code;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		
		if ( false === $cached ) {
			$cached = get_transient( $this->transient_key( $key ) );
			if ( false === $cached ) {
				return null;
			}
			wp_cache_set( $key, $cached, self::CACHE_GROUP, self::TTL_SECONDS );
		}
		
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Set districts cache
	 * 
	 * @param string $province_code Province code
	 * @param array  $districts Districts data
	 * @return bool Success
	 */
	public function set_districts( string $province_code, array $districts ): bool {
		$key = self::CACHE_DISTRICTS . $province_code;
		wp_cache_set( $key, $districts, self::CACHE_GROUP, self::TTL_SECONDS );
		return set_transient( $this->transient_key( $key ), $districts, self::TTL_SECONDS );
	}

	/**
	 * Get cached communes for a district
	 * 
	 * @param string $district_code District code
	 * @return array|null Cached communes or null
	 */
	public function get_communes( string $district_code ): ?array {
		$key    = self::CACHE_COMMUNES . $district_code;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		
		if ( false === $cached ) {
			$cached = get_transient( $this->transient_key( $key ) );
			if ( false === $cached ) {
				return null;
			}
			wp_cache_set( $key, $cached, self::CACHE_GROUP, self::TTL_SECONDS );
		}
		
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Set communes cache
	 * 
	 * @param string $district_code District code
	 * @param array  $communes Communes data
	 * @return bool Success
	 */
	public function set_communes( string $district_code, array $communes ): bool {
		$key = self::CACHE_COMMUNES . $district_code;
		wp_cache_set( $key, $communes, self::CACHE_GROUP, self::TTL_SECONDS );
		return set_transient( $this->transient_key( $key ), $communes, self::TTL_SECONDS );
	}

	/**
	 * Clear all address cache
	 * 
	 * @return bool Success
	 */
	public function clear_all(): bool {
		// Clear object cache (best-effort, may not clear all keys)
		wp_cache_flush();
		
		// Clear transients (more reliable)
		global $wpdb;
		
		$pattern = '_transient_' . self::CACHE_GROUP . '%';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);
		
		return false !== $deleted;
	}

	/**
	 * Get transient key
	 * 
	 * @param string $key Cache key
	 * @return string Transient key
	 */
	private function transient_key( string $key ): string {
		return self::CACHE_GROUP . '_' . $key;
	}
}
