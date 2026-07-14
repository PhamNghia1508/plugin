<?php
/**
 * Lightweight, network-free tests for the SPX address data layer:
 * normalizer, import validation/build, and repository queries + matching.
 *   docker run --rm -v "$PWD":/app -w /app php:7.4-cli \
 *     php wp-content/plugins/spx-express-woocommerce/tests/test-spx-address.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) ); }

$inc = dirname( __DIR__ ) . '/includes/address/';
require_once $inc . 'class-spx-address-normalizer.php';
require_once $inc . 'class-spx-address-import-service.php';
require_once $inc . 'class-spx-address-repository.php';

$GLOBALS['n'] = 0;
function t( $cond, $msg ) { $GLOBALS['n']++; if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); } echo "PASS: {$msg}\n"; }

/* ---- Normalizer ---- */
t( 'thành phố phổ yên' === SPX_Address_Normalizer::normalize( '  Thành   Phố  Phổ Yên ' ), 'normalize lowercases and collapses spaces' );
t( 'quận 1' === SPX_Address_Normalizer::normalize( 'Quận 1.' ), 'normalize strips trailing punctuation' );
t( 'da nang' === SPX_Address_Normalizer::fold( 'đà nẵng' ), 'fold removes Vietnamese diacritics incl. đ' );
t( 'phu qui' === SPX_Address_Normalizer::loose_key( 'Huyện Phú Quí' ), 'loose_key strips district prefix + folds' );
t( 'pho yen' === SPX_Address_Normalizer::loose_key( 'Thành Phố Phổ Yên' ), 'loose_key strips multi-word prefix' );
t( 'tam thanh' === SPX_Address_Normalizer::loose_key( 'Xã Tam Thanh' ), 'loose_key strips ward prefix' );

/* ---- Import validation ---- */
$svc = new SPX_Address_Import_Service();
function row( $id, $ward, $dist, $prov, $del = 'Y', $pick = 'Y', $cod = 'Y', $stat = 'Available' ) {
	return array( 'id' => (string) $id, 'ward' => $ward, 'district' => $dist, 'province' => $prov, 'delivery' => $del, 'pickup' => $pick, 'cod' => $cod, 'status' => $stat );
}
$good = array(
	row( 14634, 'Xã Tam Thanh', 'Huyện Phú Quí', 'Bình Thuận' ),
	row( 14633, 'Thị Trấn Phước Bửu', 'Huyện Xuyên Mộc', 'Bà Rịa - Vũng Tàu', 'N', 'Y', 'N' ),
	row( 14632, 'Xã Minh Đức', 'Thành Phố Phổ Yên', 'Thái Nguyên' ),
	row( 14631, 'Phường 1', 'Quận 1', 'TP. Hồ Chí Minh' ),
	row( 14630, 'Phường 2', 'Quận 1', 'TP. Hồ Chí Minh' ),
);
$res = $svc->validate_and_build( $good );
t( true === $res['ok'], 'valid rows import cleanly' );
t( 5 === $res['meta']['ward_count'] && 4 === $res['meta']['province_count'], 'counts: 5 wards across 4 provinces' );
t( 4 === $res['meta']['district_count'], 'counts: 4 districts (Quận 1 shared by 2 wards)' );

$dup = $good; $dup[] = row( 14634, 'X', 'Y', 'Z' );
t( false === $svc->validate_and_build( $dup )['ok'], 'duplicate Sort Codet ID fails the whole import' );
$has = function ( $errors, $code ) { foreach ( $errors as $e ) { if ( $e['code'] === $code ) { return true; } } return false; };
t( $has( $svc->validate_and_build( $dup )['errors'], 'duplicate_id' ), 'duplicate id reports duplicate_id' );

t( $has( $svc->validate_and_build( array( row( 1, 'W', 'D', '' ) ) )['errors'], 'province_missing' ), 'empty province fails' );
t( $has( $svc->validate_and_build( array( row( 1, '', 'D', 'P' ) ) )['errors'], 'ward_missing' ), 'empty ward fails' );
t( $has( $svc->validate_and_build( array( row( 'abc', 'W', 'D', 'P' ) ) )['errors'], 'invalid_id' ), 'non-integer id fails' );
t( $has( $svc->validate_and_build( array( row( 1, 'W', 'D', 'P', 'X' ) ) )['errors'], 'invalid_delivery' ), 'invalid Delivery value fails' );
t( $has( $svc->validate_and_build( array( row( 1, 'W', 'D', 'P', 'Y', 'Y', 'Y', '' ) ) )['errors'], 'status_missing' ), 'empty Status fails' );
t( null === $svc->validate_and_build( $dup )['dataset'], 'failed import yields no dataset (rollback intent)' );

/* ---- Repository ---- */
$repo = SPX_Address_Repository::from_array( $res['dataset'] );
t( $repo->is_available(), 'repository reports available' );
t( 4 === count( $repo->get_provinces() ), 'repository lists 4 provinces' );

$bt = $repo->find_province_by_name( 'Bình Thuận' );
t( 'matched' === $bt['status'], 'find province by exact name' );
$bt2 = $repo->find_province_by_name( 'binh thuan' );
t( 'matched' === $bt2['status'] && $bt2['code'] === $bt['code'], 'find province by diacritic-free loose name' );
t( 'none' === $repo->find_province_by_name( 'Nowhere' )['status'], 'unknown province returns none' );

$hcm = $repo->find_province_by_name( 'TP. Hồ Chí Minh' );
$dist = $repo->find_district_by_name( $hcm['code'], 'Quận 1' );
t( 'matched' === $dist['status'], 'find district within province' );
$wards = $repo->get_wards( $dist['code'] );
t( 2 === count( $wards ), 'district Quận 1 has 2 wards' );
$w1 = $repo->find_ward_by_name( $dist['code'], 'Phường 1' );
t( 'matched' === $w1['status'], 'find ward within district' );
t( true === $repo->validate_hierarchy( $hcm['code'], $dist['code'], $w1['code'] ), 'valid hierarchy passes' );
t( false === $repo->validate_hierarchy( $hcm['code'], $dist['code'], '999999' ), 'ward not under district fails hierarchy' );
t( false === $repo->validate_hierarchy( $bt['code'], $dist['code'], $w1['code'] ), 'district under wrong province fails hierarchy' );

$ward_row = $repo->get_ward( $dist['code'], $w1['code'] );
t( true === $ward_row['delivery'] && 'Available' === $ward_row['status'], 'ward carries capability flags + status' );

/* ambiguity: two districts fold to the same loose key */
$amb = $svc->validate_and_build( array(
	row( 1, 'W1', 'Huyện Ba', 'Prov X' ),
	row( 2, 'W2', 'Huyện Bá', 'Prov X' ),
) );
$repo2 = SPX_Address_Repository::from_array( $amb['dataset'] );
$px = $repo2->find_province_by_name( 'Prov X' );
t( 'ambiguous' === $repo2->find_district_by_name( $px['code'], 'Ba' )['status'], 'ambiguous loose district match is not auto-picked' );

/* ---- Resolver (pure name resolution) ---- */
require_once $inc . 'class-spx-wc-address-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-spx-shipment-readiness.php';
$resolver = new SPX_WC_Address_Resolver( $repo );

$full = $resolver->resolve_from_names( array( 'province' => 'TP. Hồ Chí Minh', 'district' => 'Quận 1', 'ward' => 'Phường 1' ), $repo );
t( true === $full['resolved'] && '' !== $full['ward_code'], 'resolver resolves full province/district/ward' );

$noward = $resolver->resolve_from_names( array( 'province' => 'TP. Hồ Chí Minh', 'district' => 'Quận 1', 'ward' => '' ), $repo );
t( false === $noward['resolved'] && in_array( 'recipient_ward_unresolved', $noward['errors'], true ), 'resolver reports missing ward but keeps province/district' );
t( '' !== $noward['province_code'] && '' !== $noward['district_code'], 'resolver still resolves province/district without ward' );

$badprov = $resolver->resolve_from_names( array( 'province' => 'Nowhere', 'district' => 'X', 'ward' => 'Y' ), $repo );
t( false === $badprov['resolved'] && in_array( 'recipient_province_unresolved', $badprov['errors'], true ), 'resolver reports unresolved province' );

$ambres = ( new SPX_WC_Address_Resolver( $repo2 ) )->resolve_from_names( array( 'province' => 'Prov X', 'district' => 'Ba', 'ward' => 'W1' ), $repo2 );
t( in_array( 'recipient_district_unresolved', $ambres['errors'], true ), 'resolver does not auto-pick an ambiguous district' );

/* ---- Readiness ---- */
$sender_ok = array( 'sender_name' => 'Shop', 'sender_phone' => '0900000000', 'sender_detail_address' => '1 St', 'sender_province_code' => 'p1', 'sender_district_code' => 'd1', 'sender_ward_code' => '1' );
$rcpt_ok   = array( 'name' => 'Buyer', 'phone' => '0988888888', 'address' => '2 St', 'province_code' => 'p2', 'district_code' => 'd2', 'ward_code' => '2' );
$parcel_ok = array( 'weight_grams' => 500, 'item_quantity' => 1 );
$payment_paid = array( 'payment_method' => 'qr_gateway', 'is_paid' => true, 'payment_state' => 'paid', 'cod_amount' => 0, 'ready_for_shipment' => true, 'reason' => '' );
$payment_cod  = array( 'payment_method' => 'cod', 'is_paid' => false, 'payment_state' => 'cash_on_delivery', 'cod_amount' => 100000, 'ready_for_shipment' => true, 'reason' => '' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok, 'payment' => $payment_cod ) );
t( true === $r['ready'], 'readiness passes with complete sender/recipient/parcel/COD' );

$codes = function ( $res ) { return array_map( function ( $e ) { return $e['code']; }, $res['errors'] ); };
$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => array_merge( $sender_ok, array( 'sender_phone' => '' ) ), 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok, 'payment' => $payment_paid ) );
t( in_array( 'sender_phone_missing', $codes( $r ), true ) && false === $r['ready'], 'readiness flags missing sender phone' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => array_merge( $rcpt_ok, array( 'ward_code' => '' ) ), 'parcel' => $parcel_ok, 'payment' => $payment_paid ) );
t( in_array( 'recipient_ward_unresolved', $codes( $r ), true ), 'readiness flags unresolved recipient ward' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => array( 'weight_grams' => 0, 'item_quantity' => 1 ), 'payment' => $payment_paid ) );
t( in_array( 'parcel_weight_invalid', $codes( $r ), true ), 'readiness flags zero weight' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => array( 'weight_grams' => 18000, 'item_quantity' => 1 ), 'payment' => $payment_paid ) );
t( in_array( 'parcel_weight_exceeded', $codes( $r ), true ), 'readiness flags overweight parcel (>17kg)' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok, 'payment' => array_merge( $payment_cod, array( 'cod_amount' => 25000000 ) ) ) );
t( in_array( 'cod_amount_exceeded', $codes( $r ), true ), 'readiness flags COD over 20,000,000 VND' );

$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok, 'payment' => array_merge( $payment_cod, array( 'cod_amount' => 'abc' ) ) ) );
t( in_array( 'cod_amount_invalid', $codes( $r ), true ), 'readiness flags non-integer COD' );

$unsupported = array_merge( $rcpt_ok, array( 'delivery_supported' => false, 'cod_supported' => true, 'service_status' => 'Available' ) );
$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $unsupported, 'parcel' => $parcel_ok, 'payment' => $payment_paid ) );
t( in_array( 'recipient_delivery_unsupported', $codes( $r ), true ), 'readiness enforces local Delivery capability when supplied' );

$unsupported_cod = array_merge( $rcpt_ok, array( 'delivery_supported' => true, 'cod_supported' => false, 'service_status' => 'Available' ) );
$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $unsupported_cod, 'parcel' => $parcel_ok, 'payment' => array_merge( $payment_cod, array( 'cod_amount' => 1000 ) ) ) );
t( in_array( 'recipient_cod_unsupported', $codes( $r ), true ), 'readiness enforces local COD capability for COD shipment' );

$inactive = array_merge( $rcpt_ok, array( 'delivery_supported' => true, 'cod_supported' => true, 'service_status' => 'Unavailable' ) );
$r = SPX_Shipment_Readiness::evaluate( array( 'sender' => $sender_ok, 'recipient' => $inactive, 'parcel' => $parcel_ok, 'payment' => $payment_paid ) );
t( in_array( 'recipient_service_unavailable', $codes( $r ), true ), 'readiness enforces current service-area status' );

$r = SPX_Shipment_Readiness::evaluate( array(
	'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok,
	'payment' => array( 'payment_method' => '', 'is_paid' => false, 'payment_state' => 'missing', 'cod_amount' => null, 'ready_for_shipment' => false, 'reason' => 'payment_method_missing' ),
) );
t( ! $r['ready'] && in_array( 'payment_method_missing', $codes( $r ), true ), 'readiness blocks missing payment method' );

$r = SPX_Shipment_Readiness::evaluate( array(
	'sender' => $sender_ok, 'recipient' => $rcpt_ok, 'parcel' => $parcel_ok,
	'payment' => array( 'payment_method' => 'qr_gateway', 'is_paid' => false, 'payment_state' => 'unconfirmed', 'cod_amount' => null, 'ready_for_shipment' => false, 'reason' => 'payment_not_confirmed' ),
) );
t( ! $r['ready'] && in_array( 'payment_not_confirmed', $codes( $r ), true ), 'readiness blocks unpaid online payment' );

echo "\nAll SPX address data-layer tests passed ({$GLOBALS['n']} assertions).\n";
