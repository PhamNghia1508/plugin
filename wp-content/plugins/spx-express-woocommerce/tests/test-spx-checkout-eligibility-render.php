<?php
/**
 * PHASE 6G-F — Location field visibility on first Checkout load.
 *
 * Pins that the "Khu vực" control must render visible whenever the cart
 * needs shipping AND SPX is available in the matched zone — even before
 * any shipping method has been chosen. The chicken-and-egg (field hidden
 * until spx_express is the chosen method) causes the field never to
 * appear on first load; this test guards against that regression.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/checkout/class-spx-checkout-eligibility.php';
$n = 0; function t( $c, $m ) { global $n; $n++; if ( ! $c ) { throw new RuntimeException( 'FAIL: ' . $m ); } echo "PASS: $m\n"; }

/* Existing contract: chosen SPX method requires selection. */
t( SPX_Checkout_Eligibility::requires_selection( true, array( 'spx_express:6' ) ), 'chosen spx_express triggers selection required' );
t( ! SPX_Checkout_Eligibility::requires_selection( false, array( 'spx_express:6' ) ), 'no-shipping cart never requires selection' );
t( ! SPX_Checkout_Eligibility::requires_selection( true, array( 'flat_rate:1' ) ), 'non-spx chosen method does not require selection' );

/* NEW contract: field must render visible when SPX is available in the zone,
   independent of what has been chosen. */
t( SPX_Checkout_Eligibility::should_render_field( true, false, true ), 'render visible: needs shipping + SPX available + none chosen' );
t( SPX_Checkout_Eligibility::should_render_field( true, true, false ), 'render visible: needs shipping + SPX chosen (even if availability unknown)' );
t( ! SPX_Checkout_Eligibility::should_render_field( false, true, true ), 'render hidden: cart does not need shipping' );
t( ! SPX_Checkout_Eligibility::should_render_field( true, false, false ), 'render hidden: needs shipping but SPX neither chosen nor available' );

echo "All eligibility-render tests passed ($n assertions).\n";
