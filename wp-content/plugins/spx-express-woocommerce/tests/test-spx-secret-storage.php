<?php
define( 'ABSPATH', __DIR__ );
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][$key] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; $GLOBALS['autoload'][$key] = $autoload; return true; }
$GLOBALS['options'] = array(); $GLOBALS['autoload'] = array();
require_once dirname( __DIR__ ) . '/includes/production/class-spx-environment.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-secret-storage.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-credential-store.php';
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
$store = new SPX_Secret_Storage( 'auth-key|secure-auth-key|logged-in-key|nonce-key' );
$plain = 'not-for-logs-secret'; $encrypted = $store->encrypt( $plain );
e( is_string( $encrypted ) && 0 === strpos( $encrypted, 'v1:' ), 'ciphertext is versioned' );
e( false === strpos( $encrypted, $plain ), 'ciphertext does not contain plaintext' );
e( $plain === $store->decrypt( $encrypted ), 'secret round trips' );
$tampered = substr( $encrypted, 0, -2 ) . 'xx';
e( '' === $store->decrypt( $tampered ), 'tampering fails closed' );
e( '' === ( new SPX_Secret_Storage( 'different-salts' ) )->decrypt( $encrypted ), 'key rotation failure is controlled' );
e( '' === $store->decrypt( 'plaintext-legacy-value' ), 'plaintext is never accepted as ciphertext' );
$credentials = new SPX_Credential_Store( $store );
e( $credentials->save( 'production', array( 'app_id' => 'db-app', 'app_secret' => 'db-secret' ) ), 'option credentials save' );
$record = $GLOBALS['options']['spx_credentials_production'];
e( 0 === strpos( $record['app_secret'], 'v1:' ) && false === strpos( json_encode( $record ), 'db-secret' ), 'database record contains ciphertext, not plaintext' );
e( false === $GLOBALS['autoload']['spx_credentials_production'], 'credential option is non-autoloaded' );
e( 'db-secret' === $credentials->get( 'production' )['app_secret'], 'encrypted option decrypts through credential store' );
$credentials->save( 'production', array( 'app_secret' => '' ) );
e( 'db-secret' === $credentials->get( 'production' )['app_secret'], 'empty secret preserves current value' );
$credentials->save( 'production', array( 'app_secret' => 'replacement-secret' ) );
e( 'replacement-secret' === $credentials->get( 'production' )['app_secret'], 'secret replacement works' );
putenv( 'SPX_PRODUCTION_APP_SECRET=server-secret' );
e( 'server-secret' === $credentials->get( 'production' )['app_secret'] && 'server' === $credentials->source( 'production', 'app_secret' ), 'server constant overrides encrypted option' );
putenv( 'SPX_PRODUCTION_APP_SECRET' );
$credentials->save( 'production', array(), array( 'app_secret' => 'yes' ) );
e( '' === $credentials->get( 'production' )['app_secret'], 'explicit deletion removes stored secret' );
echo "Secret storage tests passed.\n";
