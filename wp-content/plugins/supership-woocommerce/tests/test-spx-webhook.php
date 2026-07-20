<?php
define('ABSPATH',__DIR__.'/'); $n=0; function ok($v,$m){global $n;++$n;if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
require __DIR__.'/../includes/tracking/class-spx-webhook-verifier.php';
$v=new SPX_Webhook_Verifier(); ok(!$v->is_available(),'disabled without official docs');
foreach(array('', '{}', '{"tracking_no":"SPXVN064181227127"}', str_repeat('x',300000)) as $body){$r=$v->verify($body,array());ok(empty($r['verified']),'never accepts unsigned/altered body');ok($r['reason']==='official_signature_documentation_missing','stable blocker');}
$src=file_get_contents(__DIR__.'/../includes/tracking/class-spx-webhook-controller.php'); ok(false!==strpos($src,"'permission_callback' => '__return_true'"),'REST callback declared'); ok(substr_count($src,"status' => 503")>=1,'fail closed 503'); ok(false===strpos($src,'SPX_Tracking_Updater'),'no unverified update path');
echo "SPX webhook PASS ($n assertions) - WEBHOOK BLOCKED BY DOCUMENTATION\n";
