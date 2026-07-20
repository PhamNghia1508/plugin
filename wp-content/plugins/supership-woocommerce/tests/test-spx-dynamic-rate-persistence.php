<?php
error_reporting(E_ALL); define('ABSPATH',__DIR__); function __($t,$d=null){return $t;} function sanitize_text_field($v){return trim(strip_tags((string)$v));}
class SPX_Test_Meta_Object { public $meta=array(); public function update_meta_data($k,$v){$this->meta[$k]=$v;} }
require_once dirname(__DIR__).'/includes/rate/class-spx-dynamic-checkout-rate-service.php';
$n=0;function t($c,$m){global $n;$n++;if(!$c)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$item=new SPX_Test_Meta_Object();$order=new SPX_Test_Meta_Object();
$audit=array('_spx_rate_source'=>'spx_dynamic','_spx_rate_environment'=>'test','_spx_rate_raw_estimated'=>'21','_spx_rate_raw_vat'=>'1.56','_spx_rate_multiplier'=>1000,'_spx_rate_unit_mode'=>'experimental_thousand_vnd','_spx_rate_converted_vnd'=>'21000.00','_spx_rate_quoted_at'=>'2026-07-14T00:00:00+00:00','_spx_rate_contract_version'=>'6J-X-1','_spx_rate_cache_hit'=>'0');
SPX_Dynamic_Checkout_Rate_Service::persist_audit($item,$order,$audit);
t(array_keys($audit)===array_keys($item->meta)&&'1000'===$item->meta['_spx_rate_multiplier'],'all approved audit fields persist on shipping item'); t($item->meta===$order->meta,'all approved audit fields persist on order through CRUD');
t(!isset($item->meta['raw_request'],$item->meta['phone'],$item->meta['address']),'raw request and PII are absent');
echo "All dynamic rate persistence tests passed ($n assertions).\n";
