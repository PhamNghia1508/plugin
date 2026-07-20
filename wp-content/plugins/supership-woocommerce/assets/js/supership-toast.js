/**
 * SuperShip - toast notifications + styled confirm dialog.
 *
 * Replaces the browser's alert()/confirm() across the plugin's admin UI:
 *  - SuperShipToast.show( message, type )    -> toast now ('success'|'error'|'info')
 *  - SuperShipToast.flash( message, type )   -> toast AFTER the next page load
 *    (survives location.reload() via sessionStorage - the reload-after-action
 *    pattern used by the order metabox and shipments page)
 *  - SuperShipToast.confirm( message, opts ) -> Promise<boolean> styled dialog
 *    (opts: {yes, no, danger})
 *
 * No dependencies - plain DOM, enqueued only on the plugin's own screens.
 */
( function () {
	'use strict';

	var FLASH_KEY = 'supershipFlash';

	function getContainer() {
		var el = document.getElementById( 'supership-toasts' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'supership-toasts';
			document.body.appendChild( el );
		}
		return el;
	}

	function show( message, type, duration ) {
		type     = type || 'success';
		duration = duration || 4000;

		var toast = document.createElement( 'div' );
		toast.className = 'supership-toast supership-toast--' + type;
		toast.setAttribute( 'role', 'status' );

		var icon = document.createElement( 'span' );
		icon.className   = 'supership-toast__icon';
		icon.textContent = 'success' === type ? '✓' : ( 'error' === type ? '✕' : 'ℹ' );

		var msg = document.createElement( 'span' );
		msg.className   = 'supership-toast__msg';
		msg.textContent = message;

		toast.appendChild( icon );
		toast.appendChild( msg );
		getContainer().appendChild( toast );

		// Two frames so the enter transition reliably plays.
		requestAnimationFrame( function () {
			requestAnimationFrame( function () {
				toast.classList.add( 'is-visible' );
			} );
		} );

		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () {
				toast.remove();
			}, 300 );
		}, duration );
	}

	function flash( message, type ) {
		try {
			sessionStorage.setItem( FLASH_KEY, JSON.stringify( { m: message, t: type || 'success' } ) );
		} catch ( e ) {
			// Storage unavailable - the action still succeeds, only the toast is lost.
		}
	}

	function confirmDialog( message, opts ) {
		opts = opts || {};

		return new Promise( function ( resolve ) {
			var overlay = document.createElement( 'div' );
			overlay.className = 'supership-confirm-overlay';

			var box = document.createElement( 'div' );
			box.className = 'supership-confirm';
			box.setAttribute( 'role', 'dialog' );
			box.setAttribute( 'aria-modal', 'true' );

			var text = document.createElement( 'p' );
			text.className   = 'supership-confirm__msg';
			text.textContent = message;

			var actions = document.createElement( 'div' );
			actions.className = 'supership-confirm__actions';

			var noBtn = document.createElement( 'button' );
			noBtn.type        = 'button';
			noBtn.className   = 'button supership-confirm__no';
			noBtn.textContent = opts.no || 'Không';

			var yesBtn = document.createElement( 'button' );
			yesBtn.type        = 'button';
			yesBtn.className   = 'button supership-confirm__yes' + ( opts.danger ? ' is-danger' : ' button-primary' );
			yesBtn.textContent = opts.yes || 'Đồng ý';

			function close( result ) {
				document.removeEventListener( 'keydown', onKey );
				overlay.remove();
				resolve( result );
			}

			function onKey( e ) {
				if ( 'Escape' === e.key ) {
					close( false );
				}
			}

			noBtn.addEventListener( 'click', function () { close( false ); } );
			yesBtn.addEventListener( 'click', function () { close( true ); } );
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					close( false );
				}
			} );
			document.addEventListener( 'keydown', onKey );

			actions.appendChild( noBtn );
			actions.appendChild( yesBtn );
			box.appendChild( text );
			box.appendChild( actions );
			overlay.appendChild( box );
			document.body.appendChild( overlay );

			noBtn.focus();
		} );
	}

	// Deliver any toast stashed before the previous page reload.
	function deliverFlash() {
		try {
			var raw = sessionStorage.getItem( FLASH_KEY );
			if ( raw ) {
				sessionStorage.removeItem( FLASH_KEY );
				var data = JSON.parse( raw );
				show( data.m, data.t );
			}
		} catch ( e ) {
			// Corrupt/unavailable storage - nothing to deliver.
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', deliverFlash );
	} else {
		deliverFlash();
	}

	window.SuperShipToast = {
		show: show,
		flash: flash,
		confirm: confirmDialog
	};
}() );
