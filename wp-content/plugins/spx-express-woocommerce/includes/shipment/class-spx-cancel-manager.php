<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Cancel_Manager {
	const LOCK_PREFIX='spx_cancel_order_'; const LOCK_TTL=300;
	private $tracking_service; private $cancel_service; private $updater; private $execution_enabled;
	public function __construct($tracking_service=null,$cancel_service=null,$updater=null,$execution_enabled=null){$this->tracking_service=$tracking_service;$this->cancel_service=$cancel_service;$this->updater=$updater;$this->execution_enabled=null===$execution_enabled?(defined('SPX_ENABLE_REAL_CANCEL')&&true===SPX_ENABLE_REAL_CANCEL):(bool)$execution_enabled;}
	public static function is_status_eligible($code):bool{return '1001'===(string)$code;}

	public function check_eligibility(WC_Order$order):array{
		$tracking=strtoupper(trim((string)$order->get_meta('_spx_tracking_number',true)));if(!SPX_Tracking_Service::is_real_tracking($tracking)){return self::fail('invalid_tracking');}if('test'!==(string)$order->get_meta('_spx_shipment_environment',true)){return self::fail('environment_mismatch');}if('succeeded'===(string)$order->get_meta('_spx_cancel_state',true)){return self::fail('already_cancelled');}
		$service=$this->tracking_service?:new SPX_Tracking_Service();$result=$service->get_by_tracking_number($tracking);if(empty($result['success'])||empty($result['found'])){return array('eligible'=>false,'error_code'=>'canonical_status_unavailable','tracking_no'=>$tracking,'tracking_result'=>$result);}
		if($this->updater&&method_exists($this->updater,'apply')){$this->updater->apply($order,$result,'cancel_check');}
		if('7001'===(string)($result['status_code']??'')){$this->mark_succeeded($order);return self::fail('already_cancelled');}
		return array('eligible'=>self::is_status_eligible($result['status_code']??''),'error_code'=>self::is_status_eligible($result['status_code']??'')?'':'status_ineligible','tracking_no'=>$tracking,'tracking_result'=>$result);
	}

	public function request_cancel(WC_Order$order):array{
		$state=(string)$order->get_meta('_spx_cancel_state',true);if('succeeded'===$state){return array('success'=>true,'state'=>'succeeded','already'=>true);}if(in_array($state,array('processing','unknown'),true)){return array('success'=>false,'state'=>$state,'error_code'=>'retry_blocked');}
		if(!$this->acquire_lock($order->get_id())){return array('success'=>false,'state'=>'processing','error_code'=>'locked');}
		try{$elig=$this->check_eligibility($order);if(empty($elig['eligible'])){return array('success'=>false,'state'=>'failed','error_code'=>$elig['error_code']??'ineligible');}if(!$this->execution_enabled){return array('success'=>false,'state'=>'failed','error_code'=>'gate_6f_b_required');}
			$order->update_meta_data('_spx_cancel_state','processing');$order->update_meta_data('_spx_cancel_requested_at',gmdate('c'));$order->save();$service=$this->cancel_service?:new SPX_Cancel_Service();$results=$service->cancel(array($elig['tracking_no']));$result=$results[$elig['tracking_no']]??array('state'=>'unknown','error_code'=>'missing_result');$state=(string)($result['state']??'unknown');
			if('succeeded'===$state){$this->mark_succeeded($order);SPX_Tracking_Scheduler::schedule_canonical_verification($order->get_id());return array('success'=>true,'state'=>'succeeded');}
			if('unknown'===$state){$order->update_meta_data('_spx_cancel_state','unknown');$order->update_meta_data('_spx_cancel_requested_at',gmdate('c'));$order->save();SPX_Tracking_Scheduler::schedule_canonical_verification($order->get_id());return array('success'=>false,'state'=>'unknown','error_code'=>'unknown_result');}
			$order->update_meta_data('_spx_cancel_state','failed');$order->update_meta_data('_spx_cancel_ret_code',(int)($result['ret_code']??0));$order->update_meta_data('_spx_cancel_last_error',sanitize_key((string)($result['error_code']??'cancel_failed')));$order->save();return array('success'=>false,'state'=>'failed','error_code'=>'cancel_failed','ret_code'=>$result['ret_code']??null);
		}finally{$this->release_lock($order->get_id());}
	}

	public function reconcile(WC_Order$order):array{
		$tracking=(string)$order->get_meta('_spx_tracking_number',true);$service=$this->tracking_service?:new SPX_Tracking_Service();$result=$service->get_by_tracking_number($tracking);if(!empty($result['success'])&&!empty($result['found'])){if($this->updater&&method_exists($this->updater,'apply')){$this->updater->apply($order,$result,'cancel_reconcile');}if('7001'===(string)($result['status_code']??'')){$this->mark_succeeded($order);return array('success'=>true,'state'=>'succeeded');}}return array('success'=>false,'state'=>'unknown');
	}

	public function acquire_lock(int$order_id):bool{$key=self::LOCK_PREFIX.$order_id;$now=time();$lock=get_option($key,array());if(is_array($lock)&&!empty($lock['expires_at'])&&(int)$lock['expires_at']>$now){return false;}if($lock){delete_option($key);}return add_option($key,array('token'=>function_exists('wp_generate_uuid4')?wp_generate_uuid4():hash('sha256',uniqid('',true)),'acquired_at'=>$now,'expires_at'=>$now+self::LOCK_TTL),'',false);}
	private function release_lock(int$order_id):void{delete_option(self::LOCK_PREFIX.$order_id);}
	private function mark_succeeded(WC_Order$order):void{
		$order->update_meta_data('_spx_cancel_state','succeeded');
		$order->update_meta_data('_spx_cancelled_at',gmdate('c'));
		if(!$this->has_cancel_note($order)){$order->add_order_note(__('Vận đơn SPX đã được hủy. Trạng thái đơn WooCommerce không thay đổi.','spx-express-woocommerce'));}
		$order->update_meta_data('_spx_cancel_note_added','yes');
		$order->save();
	}
	private function has_cancel_note(WC_Order$order):bool{
		if(!function_exists('wc_get_order_notes')){return false;}
		foreach(wc_get_order_notes(array('order_id'=>$order->get_id(),'limit'=>-1,'type'=>'internal'))as$note){if(false!==strpos((string)$note->content,'Vận đơn SPX đã được hủy.')){return true;}}
		return false;
	}
	private static function fail(string$code):array{return array('eligible'=>false,'error_code'=>$code);}
}
