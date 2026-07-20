/**
 * SuperShip - Shipments (Vận đơn) admin page.
 *
 * One button: pull the latest status for every open shipment from SuperShip.
 * Feedback goes through SuperShipToast: progress text inline while running,
 * then a toast after the reload (flash survives location.reload()).
 */
( function ( $, config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	function toast( msg, type ) {
		window.SuperShipToast ? window.SuperShipToast.show( msg, type ) : window.alert( msg );
	}

	function flash( msg, type ) {
		if ( window.SuperShipToast ) {
			window.SuperShipToast.flash( msg, type );
		}
	}

	$( function () {
		var $button = $( '#supership-sync-all' );
		var $status = $( '#supership-sync-status' );

		if ( ! $button.length ) {
			return;
		}

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );
			$status.removeClass( 'is-done is-error' ).text( config.i18n.syncing );

			$.post( config.ajaxUrl, {
				action: 'supership_sync_shipments',
				nonce: config.nonce
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						flash( response.data.message, 'success' );
						window.location.reload();
						return;
					}

					var message = ( response && response.data && response.data.message )
						? response.data.message
						: config.i18n.failed;

					$status.text( '' );
					toast( message, 'error' );
					$button.prop( 'disabled', false );
				} )
				.fail( function () {
					$status.text( '' );
					toast( config.i18n.failed, 'error' );
					$button.prop( 'disabled', false );
				} );
		} );
	} );

}( window.jQuery, window.supershipShipments ) );
