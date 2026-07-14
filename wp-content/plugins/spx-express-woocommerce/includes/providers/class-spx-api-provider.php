<?php
defined( 'ABSPATH' ) || exit;

/**
 * Production SPX provider. The API foundation (config/signer/http/response/
 * account service) is available and tested, but the shipment/rate operations are
 * intentionally NOT implemented in this phase and return a controlled error.
 */
final class SPX_Api_Provider implements SPX_Shipping_Provider_Interface {
	/** @var SPX_Account_Service|null */
	private $account_service;
	/** @var SPX_Rate_Service|null */
	private $rate_service;
	/** @var SPX_Shipment_Service|null */
	private $shipment_service;
	/** @var SPX_Tracking_Service|null */
	private $tracking_service;
	/** @var string */
	private $environment;

	public function __construct( ?SPX_Account_Service $account_service = null, ?SPX_Rate_Service $rate_service = null, ?SPX_Shipment_Service $shipment_service = null, ?SPX_Tracking_Service $tracking_service = null, string $environment = 'test' ) {
		$this->account_service  = $account_service;
		$this->rate_service     = $rate_service;
		$this->shipment_service = $shipment_service;
		$this->tracking_service = $tracking_service;
		$this->environment      = SPX_Environment::normalize( $environment );
	}

	public static function for_environment( string $environment ): SPX_Api_Provider { return new self( null, null, null, null, $environment ); }
	private function config(): SPX_API_Config { return SPX_API_Config::for_environment( $this->environment ); }

	private function unavailable(): array {
		return array(
			'success' => false,
			'message' => __( 'SPX API method chưa được triển khai trong phase hiện tại.', 'spx-express-woocommerce' ),
		);
	}

	public function calculate_rate( array $shipment ): array {
		$service = $this->rate_service ?: new SPX_Rate_Service( $this->config() );
		return $service->calculate( $shipment );
	}
	public function create_shipment( array $shipment ): array {
		$service = $this->shipment_service ?: new SPX_Shipment_Service( $this->config() );
		return $service->create( $shipment );
	}
	public function get_tracking( string $tracking_number ): array {
		$service = $this->tracking_service ?: new SPX_Tracking_Service( $this->config() );
		return SPX_Shipment_Service::is_real_tracking( $tracking_number )
			? $service->get_by_tracking_number( $tracking_number )
			: $service->get_by_client_order_id( $tracking_number );
	}
	public function cancel_shipment( string $tracking_number ): array { return $this->unavailable(); }
	public function get_label( string $tracking_number ): array { return $this->unavailable(); }
}
