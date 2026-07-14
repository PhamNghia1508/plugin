<?php
$n=0; function ok($v,$m){global $n;++$n;if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$php=file_get_contents(__DIR__.'/../includes/tracking/class-spx-customer-tracking.php'); $css=file_get_contents(__DIR__.'/../assets/css/tracking.css');
foreach(array('SPX Express','_spx_tracking_number','_spx_shipment_status_label','_spx_last_sync_at','_spx_estimated_delivery_min','get_order_key','hash_equals','noopener noreferrer') as $needle){ok(false!==strpos($php,$needle),'timeline field/access '.$needle);}
foreach(array('pending_pickup','in_transit','out_for_delivery','delivered','on_hold','returned','cancelled') as $status){ok(false!==strpos($php,$status),'status mapping '.$status);}
ok(false===strpos($php,'new SPX_Tracking_Service'),'no API on render'); ok(false===strpos($php,'wp_remote_'),'no HTTP on render'); ok(false!==strpos($css,'@media(max-width:600px)'),'mobile layout'); ok(false!==strpos($css,'grid-template-columns:repeat(5,1fr)'),'desktop progress');
echo "SPX customer timeline PASS ($n assertions)\n";
