<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip HTTP Client Interface
 * 
 * Contract for making authenticated HTTP requests to SuperShip API.
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
interface SuperShip_Http_Client_Interface {
	
	/**
	 * Make an authenticated GET request
	 * 
	 * @param string $path API endpoint path (e.g., '/v1/partner/orders/info')
	 * @param array  $query_params Query parameters
	 * @return SuperShip_API_Response
	 */
	public function get( string $path, array $query_params = array() ): SuperShip_API_Response;
	
	/**
	 * Make an authenticated POST request
	 * 
	 * @param string $path API endpoint path
	 * @param array  $body Request body (will be JSON-encoded)
	 * @return SuperShip_API_Response
	 */
	public function post( string $path, array $body = array() ): SuperShip_API_Response;
}
