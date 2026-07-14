<?php
define('ABSPATH',__DIR__.'/'); define('DAY_IN_SECONDS',86400); define('MINUTE_IN_SECONDS',60);
function __($s){return $s;} function sanitize_text_field($s){return trim((string)$s);} function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function absint($v){return abs((int)$v);} function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
$n=0; function ok($v,$m){global $n;++$n;if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
require __DIR__.'/../includes/api/class-spx-tracking-service.php';
class WC_Order { private $m; function __construct($m){$this->m=$m;} function get_meta($k,$s=true){return $this->m[$k]??'';} }
require __DIR__.'/../includes/tracking/class-spx-tracking-order-query.php';
$yes=new WC_Order(array('_spx_shipment_environment'=>'test','_spx_tracking_number'=>'SPXVN064181227127','_spx_shipment_status'=>'in_transit'));
ok(SPX_Tracking_Order_Query::is_eligible($yes),'eligible sandbox shipment');
foreach(array(
 array('_spx_shipment_environment'=>'test','_spx_tracking_number'=>'SPXMOCK-20260101-ABCDEF','_spx_shipment_status'=>'created'),
 array('_spx_shipment_environment'=>'production','_spx_tracking_number'=>'SPXVN064181227127','_spx_shipment_status'=>'in_transit'),
 array('_spx_shipment_environment'=>'test','_spx_tracking_number'=>'SPXVN064181227127','_spx_shipment_status'=>'delivered')
) as $m){ok(!SPX_Tracking_Order_Query::is_eligible(new WC_Order($m)),'excluded unsafe/terminal');}
$svc=file_get_contents(__DIR__.'/../includes/api/class-spx-tracking-service.php'); ok(false!==strpos($svc,'array_chunk( array_values( $valid ), 100 )'),'batch max 100');
$sch=file_get_contents(__DIR__.'/../includes/tracking/class-spx-tracking-scheduler.php'); foreach(array('5, 2 => 15, 3 => 30','as_schedule_recurring_action','wp_schedule_event','add_option( self::LOCK','as_enqueue_async_action') as $needle){ok(false!==strpos($sch,$needle),'scheduler contract '.$needle);}
ok(false===strpos($sch,'sleep('),'no inline retry sleep'); ok(false===strpos($svc,'batch_create_order'),'tracking never creates');
echo "SPX auto tracking PASS ($n assertions)\n";
