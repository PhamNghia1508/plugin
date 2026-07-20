<?php
error_reporting(E_ALL); define('ABSPATH',__DIR__);
function __($t,$d=null){return $t;} function wc_format_decimal($v){return is_numeric($v)?(string)$v:'0';} function sanitize_text_field($v){return trim((string)$v);} function absint($v){return abs((int)$v);} function add_action(){ }
$GLOBALS['spx_notices']=array(); function wc_add_notice($message,$type='success'){$GLOBALS['spx_notices'][]=array($message,$type);} function wc_has_notice($message,$type='success'){foreach($GLOBALS['spx_notices'] as $n){if($n[0]===$message&&$n[1]===$type)return true;}return false;}
class WC_Shipping_Method { public $id,$instance_id,$method_title,$method_description,$supports,$enabled='yes',$title='Old'; public $rates=array(); public $options=array(); public $instance_form_fields=array(); public function init_instance_form_fields(){} public function init_settings(){} public function get_option($k,$d=''){return $this->options[$k]??$d;} public function get_rate_id(){return 'spx_express:1';} public function add_rate($r){$this->rates[]=$r;} }
class SPX_Test_Dynamic_Service { public $result; public function calculate($package,$fixed,$context=array()){return $this->result;} }
require_once dirname(__DIR__).'/includes/rate/class-spx-fee-conversion-contract.php';
require_once dirname(__DIR__).'/includes/class-spx-shipping-method.php';
require_once dirname(__DIR__).'/includes/admin/class-spx-admin-rate.php';
$n=0;function t($c,$m){global $n;$n++;if(!$c)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$service=new SPX_Test_Dynamic_Service();$service->result=array('add_rate'=>true,'source'=>'spx_dynamic','cost'=>'21000.00','audit'=>array('_spx_rate_source'=>'spx_dynamic'));
$method=new SPX_Shipping_Method(1,$service);$method->options=array('base_cost'=>'30000');$method->calculate_shipping(array('contents_cost'=>100000));
t(1===count($method->rates),'one shipping rate is added'); t('SPX Express – Giao tiêu chuẩn'===$method->rates[0]['label'],'customer label is exact'); t('21000.00'===$method->rates[0]['cost'],'dynamic candidate is charged');
t('spx_dynamic'===$method->rates[0]['meta_data']['_spx_rate_source'],'safe audit metadata is attached');
$service->result=array('add_rate'=>true,'source'=>'fixed_disabled_experiment','cost'=>'30000.00','audit'=>array());$method->rates=array();$method->calculate_shipping(array('contents_cost'=>100000));
t('30000.00'===$method->rates[0]['cost'],'flags-off rollback uses fixed amount');
$service->result=array('add_rate'=>false,'source'=>'fallback_fixed','cost'=>null,'audit'=>array());$method->rates=array();$method->calculate_shipping(array());t(0===count($method->rates),'fail-closed omits rate');
t(1===count($GLOBALS['spx_notices'])&&'error'===$GLOBALS['spx_notices'][0][1],'fail-closed shows one safe customer error');
t('SPX động'===SPX_Admin_Rate::checkout_source_label('spx_dynamic'),'admin distinguishes dynamic source in Vietnamese');
t('Phí cố định dự phòng'===SPX_Admin_Rate::checkout_source_label('fallback_fixed'),'admin distinguishes fallback source in Vietnamese');
t('Phí cố định'===SPX_Admin_Rate::checkout_source_label('fixed_disabled_experiment'),'admin distinguishes disabled source in Vietnamese');
t(SPX_Shipping_Method::cache_signature(false,1000,'local')!==SPX_Shipping_Method::cache_signature(true,1000,'local'),'experimental flag changes WooCommerce package cache signature');
t(SPX_Shipping_Method::cache_signature(true,1000,'local')!==SPX_Shipping_Method::cache_signature(true,1000,'production'),'production context changes package cache signature');
echo "All dynamic shipping method tests passed ($n assertions).\n";
