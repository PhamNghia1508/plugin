<?php
defined( 'ABSPATH' ) || exit;

/**
 * Verifies SPX account credentials via /open/api/v1/account/verify.
 * Has no WC_Order dependency, never stores credentials, never calls Create
 * Account, and never returns or logs the user-secret.
 */
final class SPX_Account_Service {
	const VERIFY_PATH = '/open/api/v1/account/verify';

	/** @var SPX_API_Config */  private $config;
	/** @var SPX_HTTP_Client */ private $client;

	public function __construct( SPX_API_Config $config, SPX_HTTP_Client $client ) {
		$this->config = $config;
		$this->client = $client;
	}

	/**
	 * @return array{success:bool,verified:bool,http_status:int,ret_code:int|null,message:string,retryable:bool}
	 */
	public function verify_credentials(): array {
		if ( ! $this->config->has_account_credentials() ) {
			return array(
				'success'     => false,
				'verified'    => false,
				'http_status' => 0,
				'ret_code'    => null,
				'message'     => __( 'SPX account credentials are not configured.', 'spx-express-woocommerce' ),
				'retryable'   => false,
			);
		}

		$payload  = array(
			'user_id'     => (int) $this->config->get_user_id(),
			'user_secret' => $this->config->get_user_secret(),
		);
		$response = $this->client->request( self::VERIFY_PATH, $payload );

		if ( ! $response->is_success() ) {
			return array(
				'success'     => false,
				'verified'    => false,
				'http_status' => $response->get_http_status(),
				'ret_code'    => $response->get_ret_code(),
				'message'     => $response->get_message(),
				'retryable'   => $response->is_retryable(),
			);
		}

		$data  = $response->get_data();
		$match = is_array( $data ) && array_key_exists( 'match_result', $data ) && true === $data['match_result'];

		return array(
			'success'     => true,
			'verified'    => $match,
			'http_status' => $response->get_http_status(),
			'ret_code'    => $response->get_ret_code(),
			'message'     => $match ? 'success.' : __( 'SPX account credentials did not match.', 'spx-express-woocommerce' ),
			'retryable'   => false,
		);
	}
}
