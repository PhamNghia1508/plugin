<?php
error_reporting(E_ALL); define('ABSPATH',__DIR__); function __($t,$d=null){return $t;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once dirname(__DIR__).'/includes/rate/class-spx-dynamic-checkout-rate-service.php';
$n=0;function t($c,$m){global $n;$n++;if(!$c)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$disabled=SPX_Dynamic_Checkout_Rate_Service::evaluate_gate(array('dynamic_flag'=>false,'multiplier'=>1000,'spx_environment'=>'test','wp_environment'=>'local','production_mode'=>false,'has_credentials'=>true,'sender_valid'=>true,'address_valid'=>true));
t(!$disabled['network_allowed']&&'fixed_disabled_experiment'===$disabled['source'],'disabled experiment makes no network call');
$prod=SPX_Dynamic_Checkout_Rate_Service::evaluate_gate(array('dynamic_flag'=>true,'multiplier'=>1000,'spx_environment'=>'production','wp_environment'=>'production','production_mode'=>true,'has_credentials'=>true,'sender_valid'=>true,'address_valid'=>true));
t(!$prod['network_allowed'],'production fails closed');
$bad_multiplier=SPX_Dynamic_Checkout_Rate_Service::evaluate_gate(array('dynamic_flag'=>true,'multiplier'=>1,'spx_environment'=>'test','wp_environment'=>'staging','production_mode'=>false,'has_credentials'=>true,'sender_valid'=>true,'address_valid'=>true));
t(!$bad_multiplier['network_allowed'],'non-1000 multiplier cannot call SPX');
$ok=SPX_Dynamic_Checkout_Rate_Service::evaluate_gate(array('dynamic_flag'=>true,'multiplier'=>1000,'spx_environment'=>'test','wp_environment'=>'development','production_mode'=>false,'has_credentials'=>true,'sender_valid'=>true,'address_valid'=>true));
t($ok['network_allowed'],'only complete sandbox gate permits network');
$allowed=SPX_Dynamic_Checkout_Rate_Service::approved_audit_keys();
t(!in_array('raw_request',$allowed,true)&&!in_array('raw_response',$allowed,true)&&!in_array('phone',$allowed,true),'audit allowlist excludes sensitive fields');
$tree=dirname(__DIR__);$bad=array();$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree.'/includes'));foreach($it as $f){if(!$f->isFile()||'php'!==$f->getExtension())continue;$s=file_get_contents($f->getPathname());if(false!==strpos($s,'SPX_ALLOW_SANDBOX_RATE_CHECK'))$bad[]=$f->getPathname();}
t(array()===$bad,'runtime integration gate is absent from release includes tree');
echo "All dynamic rate security tests passed ($n assertions).\n";
