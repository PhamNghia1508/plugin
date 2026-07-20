<?php
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/production/class-spx-secret-storage.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-credential-migration.php';
function e( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; }
$options = array( 'spx_test_app_secret' => 'legacy-test-secret', 'spx_test_app_id' => 'test-id' );
$storage = new SPX_Secret_Storage( 'migration-test-salts' );
$result = SPX_Credential_Migration::migrate_array( $options, $storage );
e( isset( $result['spx_credentials_test']['app_secret'] ), 'legacy test secret migrates to test record' );
e( 0 === strpos( $result['spx_credentials_test']['app_secret'], 'v1:' ), 'migrated secret is encrypted' );
e( ! isset( $result['spx_test_app_secret'] ), 'plaintext is removed only after encryption' );
e( ! isset( $result['spx_credentials_production'] ), 'test credentials are never copied to production' );
e( $result === SPX_Credential_Migration::migrate_array( $result, $storage ), 'migration is idempotent' );
echo "Credential migration tests passed.\n";
