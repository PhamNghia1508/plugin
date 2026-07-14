<?php
declare( strict_types=1 );

/**
 * Scheduler eligibility policy: environment isolation, terminal-status exclusion,
 * and missing-tracking skip (Phase 6Q). Run: php tests/test-spx-scheduler-policy.php
 */

define( 'ABSPATH', __DIR__ );
function __( $t, $d = '' ) { return $t; }
function sanitize_text_field( $t ) { return trim( (string) $t ); }

class WC_Order {}
class SPX_Stub_Order extends WC_Order {
	private $meta;
	public function __construct( array $meta ) { $this->meta = $meta; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function get_id() { return 1; }
}

// Stub only the real-tracking classifier (covered separately by test-spx-tracking).
class SPX_Tracking_Service {
	public static function is_real_tracking( $t ): bool {
		$t = strtoupper( trim( (string) $t ) );
		return '' !== $t && 0 === strpos( $t, 'SPX' ) && 0 !== stripos( $t, 'SPXMOCK' );
	}
}

require_once dirname( __DIR__ ) . '/includes/production/class-spx-environment.php';
require_once dirname( __DIR__ ) . '/includes/production/class-spx-order-environment.php';
require_once dirname( __DIR__ ) . '/includes/tracking/class-spx-tracking-order-query.php';

$failures = 0;
function check( bool $c, string $m ): void { global $failures; if ( ! $c ) { $failures++; fwrite( STDERR, "FAIL: $m\n" ); } }

$real = 'SPXVN060756809897';

// Eligible: test env + real tracking + non-terminal.
check( SPX_Tracking_Order_Query::is_eligible( new SPX_Stub_Order( array(
	'_spx_tracking_number' => $real, '_spx_shipment_environment' => 'test', '_spx_shipment_status' => 'in_transit',
) ) ), 'test + real tracking + in_transit is eligible' );

// Terminal statuses excluded (no further polling).
foreach ( array( 'delivered', 'cancelled', 'returned', 'lost', 'damaged' ) as $terminal ) {
	check( ! SPX_Tracking_Order_Query::is_eligible( new SPX_Stub_Order( array(
		'_spx_tracking_number' => $real, '_spx_shipment_environment' => 'test', '_spx_shipment_status' => $terminal,
	) ) ), "terminal '$terminal' is excluded" );
}

// Missing tracking → skip.
check( ! SPX_Tracking_Order_Query::is_eligible( new SPX_Stub_Order( array(
	'_spx_shipment_environment' => 'test', '_spx_shipment_status' => 'in_transit',
) ) ), 'missing tracking is skipped' );

// Mock tracking is not a real tracking → skip.
check( ! SPX_Tracking_Order_Query::is_eligible( new SPX_Stub_Order( array(
	'_spx_tracking_number' => 'SPXMOCK-20260101-ABCDEF', '_spx_shipment_environment' => 'test', '_spx_shipment_status' => 'in_transit',
) ) ), 'mock tracking is skipped' );

// Production-env order is not polled by the test-group query (environment isolation).
check( ! SPX_Tracking_Order_Query::is_eligible( new SPX_Stub_Order( array(
	'_spx_tracking_number' => $real, '_spx_shipment_environment' => 'production', '_spx_shipment_status' => 'in_transit',
) ) ), 'production env excluded from test eligibility' );

if ( 0 === $failures ) { echo "OK test-spx-scheduler-policy\n"; exit( 0 ); }
fwrite( STDERR, "$failures failure(s)\n" ); exit( 1 );
