<?php
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
function __( $s ) { return $s; } function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); } function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
$n=0; function ok( $v, $m ) { global $n; ++$n; if ( ! $v ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require __DIR__ . '/../includes/api/class-spx-tracking-status-mapper.php';
require __DIR__ . '/../includes/tracking/class-spx-tracking-route-parser.php';
require __DIR__ . '/../includes/tracking/class-spx-tracking-event-repository.php';
$base = array( 'tracking_no'=>'SPXVN064181227127','status_code'=>'2001','event_timestamp'=>1700000000,'customer_message'=>'Đã đến kho' );
ok( SPX_Tracking_Event_Repository::event_key($base) === SPX_Tracking_Event_Repository::event_key($base), 'deterministic key' );
$id=$base; $id['event_id']='evt-1'; $id['customer_message']='changed'; ok( SPX_Tracking_Event_Repository::event_key($id) === hash('sha256','id|evt-1'), 'official id wins' );
$routes = array(
 array('status_code'=>'2006','message'=>'Đang giao','timestamp'=>1700000200000),
 array('status_code'=>'2001','message'=>'call 0912345678 at address','timestamp'=>1700000100),
 array('status_code'=>'4001','message'=>'Đã giao ✓','timestamp'=>'bad'),
);
$parsed=SPX_Tracking_Route_Parser::parse($routes,'SPXVN064181227127');
ok(count($parsed)===2,'invalid timestamp removed'); ok($parsed[0]['event_timestamp']===1700000100,'sorted ascending'); ok(false===strpos($parsed[0]['customer_message'],'0912'),'PII removed'); ok(false!==strpos($parsed[1]['customer_message'],'giao'),'unicode message retained');
$schema=file_get_contents(__DIR__.'/../includes/tracking/class-spx-tracking-event-repository.php');
foreach(array('raw_request','raw_response','phone','address','signature','secret') as $bad){ ok(false===strpos($schema,"'{$bad}' =>"),'no forbidden stored field '.$bad); }
echo "SPX tracking events PASS ($n assertions)\n";
