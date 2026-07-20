<?php
define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
require_once dirname( __DIR__ ) . '/includes/admin/class-spx-admin-production.php';
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
$existing = array( 'app_id' => 'id-1', 'app_secret' => 'cipher-1', 'user_id' => 'user-1', 'user_secret' => 'cipher-2', 'shop_id' => 'shop-1' );
$clean = SPX_Admin_Production::merge_submission( $existing, array( 'app_id' => 'id-2', 'app_secret' => '', 'user_secret' => '', 'shop_id' => 'shop-2' ) );
e( 'cipher-1' === $clean['app_secret'] && 'cipher-2' === $clean['user_secret'], 'blank secret preserves stored secret' );
$deleted = SPX_Admin_Production::merge_submission( $existing, array( 'delete_app_secret' => 'yes' ) );
e( '' === $deleted['app_secret'], 'secret deletion must be explicit' );
e( ! array_key_exists( 'base_url', $clean ), 'settings cannot accept custom base URL' );
e( 'readiness_pending' === SPX_Admin_Production::sanitize_requested_state( 'enabled' ), 'settings cannot enable production offline' );
e( 'disabled' === SPX_Admin_Production::sanitize_requested_state( 'disabled' ), 'settings can disable production' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-spx-admin-production.php' );
foreach ( array( "'POST'", 'manage_woocommerce', 'check_admin_referer', 'wp_safe_redirect', 'autocomplete="new-password"', "SPX_Production_Gate::allows( 'account_verify'", 'SPX_Production_Verification_Store::mark_verified', 'SPX_Production_Verification_Store::invalidate', 'get_transient( self::COOLDOWN_KEY' ) as $contract ) { e( false !== strpos( $source, $contract ), 'admin security contract: ' . $contract ); }
e( false === strpos( $source, 'SPX_TEST_APP_SECRET' ), 'production settings never reference sandbox secret fallback' );
echo "Production settings tests passed.\n";
