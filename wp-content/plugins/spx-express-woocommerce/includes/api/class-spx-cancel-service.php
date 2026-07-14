<?php
defined( 'ABSPATH' ) || exit;

/** One-shot cancel transport. Callers must perform eligibility and idempotency checks. */
final class SPX_Cancel_Service {
	const ENDPOINT = '/open/api/v1/order/batch_cancel_order';
	const MAX_BATCH = 100;
	/** @var SPX_API_Config */ private $config;
	/** @var SPX_Http_Client_Interface */ private $client;
	public function __construct( SPX_API_Config $config = null, SPX_Http_Client_Interface $client = null ) { $this->config = $config ?: SPX_API_Config::for_test(); $this->client = $client ?: new SPX_HTTP_Client( $this->config, new SPX_Request_Signer() ); }

	public function cancel( array $tracking_numbers ): array {
		$valid=array();foreach($tracking_numbers as $tracking){$tracking=strtoupper(trim((string)$tracking));if(SPX_Tracking_Service::is_real_tracking($tracking)){$valid[$tracking]=$tracking;}}
		$out=array();foreach(array_chunk(array_values($valid),self::MAX_BATCH)as$chunk){$out=array_merge($out,$this->cancel_chunk($chunk));}return $out;
	}

	private function cancel_chunk( array $trackings ): array {
		if ( SPX_API_Config::TEST_ENV !== $this->config->get_environment() || ! $this->config->has_account_credentials() ) { return $this->all( $trackings, 'failed', 'configuration_error', null ); }
		$response=$this->client->request(self::ENDPOINT,array('user_id'=>(int)$this->config->get_user_id(),'user_secret'=>$this->config->get_user_secret(),'tracking_no_list'=>$trackings)); // exactly once; never retry here.
		if(!$response->is_success()){$unknown=SPX_API_Error_Mapper::CAT_TRANSPORT===$response->get_category()||$response->get_http_status()>=500||$response->is_retryable();return $this->all($trackings,$unknown?'unknown':'failed','api_error',$response->get_ret_code());}
		$data=$response->get_data();if(!is_array($data)){return $this->all($trackings,'unknown','malformed_response',null);}
		$out=array();foreach((array)($data['tracking_no_list']??array())as$t){$t=strtoupper(trim((string)$t));if(in_array($t,$trackings,true)){$out[$t]=array('success'=>true,'state'=>'succeeded','tracking_no'=>$t,'ret_code'=>0);}}
		foreach((array)($data['fail_list']??array())as$f){$t=strtoupper(trim((string)($f['tracking_no']??'')));if(!in_array($t,$trackings,true)){continue;}$ret=(int)($f['ret_code']??0);$out[$t]=array('success'=>false,'state'=>'failed','error_code'=>'cancel_rejected','ret_code'=>$ret,'message'=>$ret?SPX_API_Error_Mapper::safe_message($ret):__('SPX could not cancel the shipment.','spx-express-woocommerce'));}
		foreach($trackings as$t){if(!isset($out[$t])){$out[$t]=array('success'=>false,'state'=>'unknown','error_code'=>'missing_result','ret_code'=>null,'message'=>__('SPX did not confirm the cancel result.','spx-express-woocommerce'));}}return$out;
	}

	private function all(array$trackings,string$state,string$code,$ret):array{$out=array();foreach($trackings as$t){$out[$t]=array('success'=>false,'state'=>$state,'error_code'=>$code,'ret_code'=>$ret,'message'=>$ret?SPX_API_Error_Mapper::safe_message((int)$ret):__('The SPX cancel result is unknown.','spx-express-woocommerce'));}return$out;}
}
