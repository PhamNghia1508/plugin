/**
 * Copy-to-clipboard for the affiliate referral links.
 *
 * Uses the async Clipboard API where available and falls back to selecting the
 * text + execCommand, which is still what non-HTTPS and older mobile browsers
 * need - affiliates often open this page on a phone.
 */
( function () {
	'use strict';

	var i18n = window.wcAffiliateI18n || {
		copied: 'Đã sao chép!',
		copyError: 'Không sao chép được, vui lòng chọn và copy thủ công.'
	};

	function flash( button, text ) {
		var original = button.getAttribute( 'data-original' ) || button.textContent;
		button.setAttribute( 'data-original', original );
		button.textContent = text;
		button.classList.add( 'is-copied' );

		setTimeout( function () {
			button.textContent = original;
			button.classList.remove( 'is-copied' );
		}, 1800 );
	}

	function legacyCopy( link, button ) {
		var field = document.createElement( 'textarea' );
		field.value = link;
		field.setAttribute( 'readonly', '' );
		field.style.position = 'fixed';
		field.style.opacity = '0';
		document.body.appendChild( field );
		field.select();
		field.setSelectionRange( 0, field.value.length );

		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( field );

		if ( ok ) {
			flash( button, i18n.copied );
		} else {
			window.alert( i18n.copyError );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.wc-aff-copy' ) : null;
		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var link = button.getAttribute( 'data-link' ) || '';
		if ( ! link ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( link ).then(
				function () {
					flash( button, i18n.copied );
				},
				function () {
					legacyCopy( link, button );
				}
			);
			return;
		}

		legacyCopy( link, button );
	} );
}() );
