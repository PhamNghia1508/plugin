<?php
defined( 'ABSPATH' ) || exit;

/** Sandbox-only orchestrator for the experimental dynamic checkout rate. */
final class SPX_Dynamic_Checkout_Rate_Service {
	const POLICY_FIXED_FALLBACK = 'fixed_fallback';
	const POLICY_FAIL_CLOSED = 'fail_closed';
	/** @var SPX_Checkout_Rate_Request_Builder */ private $builder;
	/** @var SPX_Checkout_Rate_Cache */ private $cache;
	/** @var mixed */ private $rate_provider;
	/** @var array|null */ private $gate_override;

	public function __construct( SPX_Checkout_Rate_Request_Builder $builder = null, SPX_Checkout_Rate_Cache $cache = null, $rate_provider = null, array $gate_override = null ) {
		$this->builder = $builder ?: new SPX_Checkout_Rate_Request_Builder();
		$this->cache = $cache ?: new SPX_Checkout_Rate_Cache();
		$this->rate_provider = $rate_provider;
		$this->gate_override = $gate_override;
	}

	public static function evaluate_gate( array $gate ): array {
		if ( true !== ( $gate['dynamic_flag'] ?? false ) ) { return array( 'network_allowed'=>false, 'source'=>'fixed_disabled_experiment', 'reason'=>'disabled' ); }
		$production = 'production' === (string)($gate['spx_environment']??'') || 'production' === (string)($gate['wp_environment']??'') || ! empty($gate['production_mode']);
		$local_env = in_array( (string)($gate['wp_environment']??''), array('local','development','staging'), true );
		$ready = 1000 === (int)($gate['multiplier']??0) && 'test' === (string)($gate['spx_environment']??'') && $local_env && ! $production && ! empty($gate['has_credentials']) && ! empty($gate['sender_valid']) && ! empty($gate['address_valid']);
		return $ready ? array('network_allowed'=>true,'source'=>'spx_dynamic','reason'=>'') : array('network_allowed'=>false,'source'=>'fallback_fixed','reason'=>$production?'production_blocked':'prerequisite_failed');
	}

	public function calculate( array $package, $fixed_cost, array $context = array() ): array {
		$fixed = number_format( max(0,(float)$fixed_cost), 2, '.', '' );
		$selection = isset($context['selection']) && is_array($context['selection']) ? $context['selection'] : $this->current_selection();
		$sender = isset($context['sender']) && is_array($context['sender']) ? $context['sender'] : SPX_Sender_Profile::get();
		$config = SPX_API_Config::for_test();
		$gate = $this->gate_override ?: $this->runtime_gate($selection,$sender,$config,$context);
		$decision = self::evaluate_gate($gate);
		if ( empty($decision['network_allowed']) ) { return $this->fallback($fixed,$decision['source'],$decision['reason'],$context); }

		$checkout = $this->checkout_context($package,$context);
		$built = $this->builder->build($package,$selection,$sender,$checkout);
		if ( empty($built['success']) ) { return $this->fallback($fixed,'fallback_fixed',(string)($built['error_code']??'request_invalid'),$context); }
		$shipment = $built['shipment'];
		$key = $this->cache->make_key(array(
			'environment'=>'test',
			'sender_location'=>(string)$sender['sender_province_code'].'|'.(string)$sender['sender_district_code'].'|'.(string)$sender['sender_ward_code'],
			'recipient_location'=>(string)$selection['province_id'].'|'.(string)$selection['district_id'].'|'.(string)$selection['ward_id'],
			'service_type'=>(int)$shipment['service_type'], 'weight_grams'=>(int)$shipment['weight_grams'],
			'dimensions'=>(string)($shipment['length_cm']??0).'|'.(string)($shipment['width_cm']??0).'|'.(string)($shipment['height_cm']??0),
			'cart_hash'=>$this->cart_hash(), 'package_hash'=>$this->package_hash($package), 'is_cod'=>!empty($shipment['is_cod']), 'cod_amount'=>(int)$shipment['cod_amount'],
			'contract_version'=>SPX_Fee_Conversion_Contract::VERSION, 'unit_mode'=>SPX_Fee_Conversion_Contract::MODE_EXPERIMENTAL_THOUSAND_VND,
			'parcel_policy'=>SPX_Parcel_Builder::configured_policy(), 'parcel_policy_version'=>SPX_Parcel_Builder::POLICY_VERSION,
			'parcel_defaults'=>function_exists('wp_json_encode')?wp_json_encode(SPX_Parcel_Builder::configured_defaults()):json_encode(SPX_Parcel_Builder::configured_defaults()),
		));
		$result = $this->cache->remember($key,function() use ($shipment,$config){
			$provider = $this->rate_provider ?: SPX_Api_Provider::for_environment('test');
			$quote = is_callable($provider) ? call_user_func($provider,$shipment) : $provider->calculate_rate($shipment);
			if ( !is_array($quote) || empty($quote['success']) ) { return array('success'=>false,'error_code'=>sanitize_key((string)($quote['error_code']??'api_error'))); }
			$conversion=(new SPX_Fee_Conversion_Contract(SPX_Fee_Conversion_Contract::MODE_EXPERIMENTAL_THOUSAND_VND,1000))->convert($quote['estimated_shipping_fee']??null);
			if(empty($conversion['success']))return $conversion;
			return array('success'=>true,'quote'=>$this->safe_quote($quote),'conversion'=>$conversion);
		});
		if(empty($result['success']))return $this->fallback($fixed,'fallback_fixed',(string)($result['error_code']??'rate_failed'),$context);
		$audit=$this->audit_from_quote($result['quote'],$result['conversion'],!empty($result['cache_hit']));
		return array('add_rate'=>true,'source'=>'spx_dynamic','cost'=>$result['conversion']['converted_vnd'],'audit'=>$audit);
	}

	private function runtime_gate(array $selection,array $sender,SPX_API_Config $config,array $context):array{
		$env=function_exists('wp_get_environment_type')?wp_get_environment_type():(defined('WP_ENVIRONMENT_TYPE')?WP_ENVIRONMENT_TYPE:'production');
		$address=false;
		if(isset($selection['province_id'],$selection['district_id'],$selection['ward_id'],$selection['dataset_version'])){$v=(new SPX_Checkout_Address_Service())->validate_selection((string)$selection['province_id'],(string)$selection['district_id'],(string)$selection['ward_id'],$this->is_cod(),(string)$selection['dataset_version']);$address=!empty($v['valid']);}
		$state=function_exists('get_option')?(string)get_option('spx_production_state','disabled'):'disabled';
		return array('dynamic_flag'=>defined('SPX_EXPERIMENTAL_DYNAMIC_RATE')&&true===SPX_EXPERIMENTAL_DYNAMIC_RATE,'multiplier'=>defined('SPX_EXPERIMENTAL_FEE_MULTIPLIER')?(int)SPX_EXPERIMENTAL_FEE_MULTIPLIER:0,'spx_environment'=>$config->get_environment(),'wp_environment'=>$env,'production_mode'=>'disabled'!==$state||'production'===($context['instance_environment']??''),'has_credentials'=>$config->has_account_credentials(),'sender_valid'=>SPX_Sender_Profile::is_complete(),'address_valid'=>$address);
	}

	private function fallback(string $fixed,string $source,string $reason,array $context):array{
		$policy=(string)($context['fallback_policy']??(defined('SPX_EXPERIMENTAL_DYNAMIC_RATE_FALLBACK_POLICY')?SPX_EXPERIMENTAL_DYNAMIC_RATE_FALLBACK_POLICY:self::POLICY_FIXED_FALLBACK));
		// A fixed fallback is only a valid customer charge when it is a positive amount.
		// An empty/zero/invalid fixed fee (unconfigured or corrupted instance settings)
		// must never be materialised as a silent free-shipping rate — fail closed instead.
		$has_fixed=is_numeric($fixed)&&(float)$fixed>0.0;
		if(!$has_fixed){$reason='fixed_unconfigured';}
		$emit=self::POLICY_FAIL_CLOSED!==$policy&&$has_fixed;
		$audit=array('_spx_rate_source'=>$source,'_spx_rate_environment'=>'test','_spx_rate_fallback_reason'=>sanitize_key($reason),'_spx_rate_unit_mode'=>'unconfirmed','_spx_rate_contract_version'=>SPX_Fee_Conversion_Contract::VERSION,'_spx_rate_cache_hit'=>'0');
		return array('add_rate'=>$emit,'source'=>$source,'cost'=>$emit?$fixed:null,'audit'=>$audit);
	}

	private function current_selection():array{if(!class_exists('SPX_Classic_Checkout_Address'))return array();return SPX_Classic_Checkout_Address::get_prefill_for_blocks(new SPX_Checkout_Address_Service());}
	private function checkout_context(array $package,array $context):array{
		$customer=function_exists('WC')&&WC()?WC()->customer:null;
		$context['is_cod']=$context['is_cod']??$this->is_cod();
		$context['cod_amount']=$context['cod_amount']??max(0,(int)round((float)($package['contents_cost']??0)));
		$context['declared_value']=$context['declared_value']??$context['cod_amount'];
		if($customer){foreach(self::customer_recipient_context($customer) as $key=>$value){if(!isset($context[$key])||''===trim((string)$context[$key]))$context[$key]=$value;}}
		if(isset($_POST['post_data'])&&is_string($_POST['post_data'])){foreach(self::classic_posted_recipient_context(wp_unslash($_POST['post_data'])) as $key=>$value){if(''!==trim((string)$value))$context[$key]=$value;}}
		return $context;
	}
	public static function customer_recipient_context($customer):array{
		$name=trim((string)$customer->get_shipping_first_name().' '.(string)$customer->get_shipping_last_name());
		if(''===$name)$name=trim((string)$customer->get_billing_first_name().' '.(string)$customer->get_billing_last_name());
		$address=trim((string)$customer->get_shipping_address_1().' '.(string)$customer->get_shipping_address_2());
		if(''===$address)$address=trim((string)$customer->get_billing_address_1().' '.(string)$customer->get_billing_address_2());
		$phone=method_exists($customer,'get_shipping_phone')?(string)$customer->get_shipping_phone():'';
		if(''===trim($phone))$phone=(string)$customer->get_billing_phone();
		return array('recipient_name'=>$name,'recipient_phone'=>$phone,'recipient_address'=>$address);
	}
	public static function classic_posted_recipient_context(string $posted):array{
		$data=array();parse_str($posted,$data);$prefix=!empty($data['ship_to_different_address'])?'shipping':'billing';
		$name=trim(sanitize_text_field((string)($data[$prefix.'_first_name']??'')).' '.sanitize_text_field((string)($data[$prefix.'_last_name']??'')));
		if('billing'===$prefix&&isset($data['billing_full_name']))$name=trim(preg_replace('/\s+/u',' ',sanitize_text_field((string)$data['billing_full_name'])));
		$address=trim(sanitize_text_field((string)($data[$prefix.'_address_1']??'')).' '.sanitize_text_field((string)($data[$prefix.'_address_2']??'')));
		if('shipping'===$prefix&&''===$name)$name=trim(sanitize_text_field((string)($data['billing_first_name']??'')).' '.sanitize_text_field((string)($data['billing_last_name']??'')));
		if('shipping'===$prefix&&''===$address)$address=trim(sanitize_text_field((string)($data['billing_address_1']??'')).' '.sanitize_text_field((string)($data['billing_address_2']??'')));
		return array('recipient_name'=>$name,'recipient_phone'=>sanitize_text_field((string)($data['billing_phone']??'')),'recipient_address'=>$address);
	}
	private function is_cod():bool{return function_exists('WC')&&WC()&&WC()->session&&'cod'===(string)WC()->session->get('chosen_payment_method','');}
	private function cart_hash():string{return function_exists('WC')&&WC()&&WC()->cart&&method_exists(WC()->cart,'get_cart_hash')?(string)WC()->cart->get_cart_hash():'';}
	private function package_hash(array $package):string{$rows=array();foreach((array)($package['contents']??array()) as $line){$rows[]=array((int)($line['product_id']??0),(int)($line['variation_id']??0),(int)($line['quantity']??0),(string)($line['line_total']??''));}return hash('sha256',function_exists('wp_json_encode')?wp_json_encode($rows):json_encode($rows));}
	private function safe_quote(array $quote):array{$out=array();foreach(array('estimated_shipping_fee','basic_shipping_fee','vat_fee','cod_service_fee','high_value_processing_fee','voucher_shipping_fee','checked_at','environment') as $k){if(isset($quote[$k])&&(is_numeric($quote[$k])||in_array($k,array('checked_at','environment'),true)))$out[$k]=$quote[$k];}return $out;}
	private function audit_from_quote(array $q,array $c,bool $hit):array{$a=array('_spx_rate_source'=>'spx_dynamic','_spx_rate_environment'=>'test','_spx_rate_multiplier'=>1000,'_spx_rate_unit_mode'=>'experimental_thousand_vnd','_spx_rate_converted_vnd'=>$c['converted_vnd'],'_spx_rate_quoted_at'=>sanitize_text_field((string)($q['checked_at']??gmdate('c'))),'_spx_rate_contract_version'=>SPX_Fee_Conversion_Contract::VERSION,'_spx_rate_cache_hit'=>$hit?'1':'0');$map=array('_spx_rate_raw_estimated'=>'estimated_shipping_fee','_spx_rate_raw_basic'=>'basic_shipping_fee','_spx_rate_raw_vat'=>'vat_fee','_spx_rate_raw_cod'=>'cod_service_fee','_spx_rate_raw_high_value'=>'high_value_processing_fee','_spx_rate_raw_voucher'=>'voucher_shipping_fee');foreach($map as $m=>$k){if(isset($q[$k]))$a[$m]=(string)(0+$q[$k]);}return $a;}

	public static function approved_audit_keys():array{return array('_spx_rate_source','_spx_rate_environment','_spx_rate_raw_estimated','_spx_rate_raw_basic','_spx_rate_raw_vat','_spx_rate_raw_cod','_spx_rate_raw_high_value','_spx_rate_raw_voucher','_spx_rate_multiplier','_spx_rate_unit_mode','_spx_rate_converted_vnd','_spx_rate_quoted_at','_spx_rate_contract_version','_spx_rate_cache_hit','_spx_rate_fallback_reason');}
	public static function persist_audit($item,$order,array $audit):void{foreach(self::approved_audit_keys() as $key){if(!array_key_exists($key,$audit))continue;$value=is_scalar($audit[$key])?sanitize_text_field((string)$audit[$key]):'';$item->update_meta_data($key,$value);$order->update_meta_data($key,$value);}}
}
