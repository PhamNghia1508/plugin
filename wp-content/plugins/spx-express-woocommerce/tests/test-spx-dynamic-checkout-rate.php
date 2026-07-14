<?php
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wc_get_weight( $value, $unit ) { return (float) $value * 1000; }
function wc_get_product( $id ) { return $GLOBALS['spx_products'][ $id ] ?? null; }
function get_transient( $key ) { return $GLOBALS['spx_transients'][ $key ]['value'] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['spx_transients'][ $key ]=array('value'=>$value,'ttl'=>$ttl); return true; }
class SPX_API_Config { public static function for_test(){return new self();} public function get_environment(){return 'test';} public function has_account_credentials(){return true;} }
class SPX_Test_Product {
	private $weight; private $virtual; private $downloadable; private $variation; private $parent; private $dims;
	public function __construct( $weight, $virtual=false, $downloadable=false, $variation=false, $parent=0, $dims=array() ) { $this->weight=$weight; $this->virtual=$virtual; $this->downloadable=$downloadable; $this->variation=$variation; $this->parent=$parent; $this->dims=$dims; }
	public function needs_shipping(){ return ! $this->virtual; } public function is_virtual(){ return $this->virtual; } public function is_downloadable(){ return $this->downloadable; }
	public function get_weight(){ return $this->weight; } public function is_type($type){ return $this->variation && 'variation'===$type; } public function get_parent_id(){ return $this->parent; }
	public function get_length(){ return $this->dims[0] ?? ''; } public function get_width(){ return $this->dims[1] ?? ''; } public function get_height(){ return $this->dims[2] ?? ''; }
}
class SPX_Test_Customer_Context { public function get_shipping_first_name(){return '';} public function get_shipping_last_name(){return '';} public function get_shipping_address_1(){return '';} public function get_shipping_address_2(){return '';} public function get_shipping_phone(){return '0970000000';} public function get_billing_first_name(){return 'Test';} public function get_billing_last_name(){return 'Buyer';} public function get_billing_address_1(){return 'Test billing address';} public function get_billing_address_2(){return '';} public function get_billing_phone(){return '0980000000';} }
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-request-builder.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-fee-conversion-contract.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-checkout-rate-cache.php';
require_once dirname( __DIR__ ) . '/includes/rate/class-spx-dynamic-checkout-rate-service.php';
$n=0; function t($c,$m){global $n;$n++;if(!$c)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$validator = function( $selection, $is_cod ) { return array( 'valid'=>true, 'snapshot'=>array_merge( $selection, array( 'province_name'=>'TP HCM','district_name'=>'Quan 1','ward_name'=>'Da Kao','delivery_supported'=>true,'cod_supported'=>true ) ) ); };
$builder = new SPX_Checkout_Rate_Request_Builder( $validator );
$sender = array( 'sender_name'=>'Test Shop','sender_phone'=>'0900000000','sender_detail_address'=>'Test address','sender_province_code'=>'p_sender','sender_district_code'=>'d_sender','sender_ward_code'=>'1','sender_province_name'=>'TP HCM','sender_district_name'=>'Quan 1','sender_ward_name'=>'Ben Nghe','service_type'=>1,'collect_type'=>2 );
$selection = array( 'province_id'=>'p_recipient','district_id'=>'d_recipient','ward_id'=>'2','dataset_version'=>'v1' );
$parent = new SPX_Test_Product( '0.6', false, false, false, 0, array(20,10,5) ); $GLOBALS['spx_products'][99]=$parent;
$variation = new SPX_Test_Product( '', false, false, true, 99 ); $virtual = new SPX_Test_Product( '', true );
$package = array( 'contents'=>array( array('data'=>$variation,'quantity'=>2,'line_total'=>300000), array('data'=>$virtual,'quantity'=>4,'line_total'=>10000) ), 'contents_cost'=>310000, 'destination'=>array('first_name'=>'Test','last_name'=>'Buyer','phone'=>'0980000000','address'=>'Test recipient address') );
$result = $builder->build( $package, $selection, $sender, array( 'is_cod'=>false, 'cod_amount'=>0 ) );
t( $result['success'] && 1200 === $result['shipment']['weight_grams'], 'variation inherits parent weight and quantity aggregates' );
t( 2 === $result['shipment']['items'][0]['quantity'] && 1 === count($result['shipment']['items']), 'virtual item is excluded' );
t( 20.0 === $result['shipment']['length_cm'], 'valid product dimensions aggregate safely' );
t( 'p_recipient' === $result['shipment']['recipient']['province_code'], 'canonical recipient IDs are used' );
t( 2 === $result['shipment']['collect_type'], 'drop-off collect type is retained' );
$missing = new SPX_Test_Product( '' );
$bad = $builder->build( array_merge($package,array('contents'=>array(array('data'=>$missing,'quantity'=>1,'line_total'=>1)))), $selection, $sender, array() );
t( ! $bad['success'] && 'missing_weight' === $bad['error_code'], 'missing physical weight blocks request' );
$denied = new SPX_Checkout_Rate_Request_Builder( function(){ return array('valid'=>false,'error'=>'cod_unsupported'); } );
$bad2 = $denied->build( $package, $selection, $sender, array('is_cod'=>true,'cod_amount'=>100000) );
t( ! $bad2['success'] && 'cod_unsupported' === $bad2['error_code'], 'COD capability is revalidated before request' );
t( false === strpos( json_encode($bad2), '0980000000' ), 'failure result contains no PII' );

/* Full orchestrator: provider once, conversion, cache, suspicious fallback. */
$GLOBALS['spx_transients']=array(); $api_calls=0; $raw_fee=21;
$provider=function($shipment)use(&$api_calls,&$raw_fee){$api_calls++;return array('success'=>true,'estimated_shipping_fee'=>$raw_fee,'vat_fee'=>1.56,'checked_at'=>'2026-07-14T00:00:00+00:00','environment'=>'test');};
$gate=array('dynamic_flag'=>true,'multiplier'=>1000,'spx_environment'=>'test','wp_environment'=>'local','production_mode'=>false,'has_credentials'=>true,'sender_valid'=>true,'address_valid'=>true);
$dynamic=new SPX_Dynamic_Checkout_Rate_Service($builder,new SPX_Checkout_Rate_Cache(),$provider,$gate);
$ctx=array('selection'=>$selection,'sender'=>$sender,'recipient_name'=>'Test Buyer','recipient_phone'=>'0980000000','recipient_address'=>'Test recipient address','is_cod'=>false,'cod_amount'=>0);
$quoted=$dynamic->calculate($package,30000,$ctx);
t('spx_dynamic'===$quoted['source']&&'21000.00'===$quoted['cost'],'orchestrator converts only estimated fee into candidate');
t('1.56'===$quoted['audit']['_spx_rate_raw_vat']&&'experimental_thousand_vnd'===$quoted['audit']['_spx_rate_unit_mode'],'raw VAT is audit-only and unit stays experimental');
$cached=$dynamic->calculate($package,30000,$ctx);
t(1===$api_calls&&'1'===$cached['audit']['_spx_rate_cache_hit'],'same request uses cache and avoids duplicate provider call');
$raw_fee=3000; $package2=$package; $package2['contents'][0]['line_total']=300001;
$fallback=$dynamic->calculate($package2,30000,$ctx);
t('fallback_fixed'===$fallback['source']&&'30000.00'===$fallback['cost'],'implausible converted fee uses fixed fallback');
$package3=$package2; $package3['contents'][0]['line_total']=300002;
$closed=$dynamic->calculate($package3,30000,array_merge($ctx,array('fallback_policy'=>'fail_closed')));
t(!$closed['add_rate']&&null===$closed['cost'],'fail_closed policy emits no customer rate');
$customer_context=SPX_Dynamic_Checkout_Rate_Service::customer_recipient_context(new SPX_Test_Customer_Context());
t('Test Buyer'===$customer_context['recipient_name']&&'Test billing address'===$customer_context['recipient_address'],'Classic customer context falls back from empty shipping fields to billing fields');
t('0970000000'===$customer_context['recipient_phone'],'Blocks customer context prefers the Store API shipping phone');
$posted_context=SPX_Dynamic_Checkout_Rate_Service::classic_posted_recipient_context('billing_first_name=Test&billing_last_name=Buyer&billing_phone=0980000000&billing_address_1=Test+posted+address');
t('Test Buyer'===$posted_context['recipient_name']&&'0980000000'===$posted_context['recipient_phone']&&'Test posted address'===$posted_context['recipient_address'],'Classic update_order_review post data is sanitized into an in-memory recipient context');
echo "All dynamic checkout request tests passed ($n assertions).\n";
