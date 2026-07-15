( function ( $, config ) {
	'use strict';
	if ( ! config ) { return; }

	// ── State ─────────────────────────────────────────────────────────
	var $control, $display, $displayText, $panel, $search, $list, $breadcrumb;
	var $provinceInput, $districtInput, $wardInput, $versionInput, $feedback;
	var requestId = 0; // latest-request-wins counter.
	var debounceTimer = null;
	var currentLevel = 'province'; // province | district | ward
	var selectedProvince = { id: '', label: '' };
	var selectedDistrict = { id: '', label: '' };
	var panelOpen = false;

	// ── Helpers ───────────────────────────────────────────────────────
	function api( path, params ) {
		var id = ++requestId;
		var url = new window.URL( config.restUrl, window.location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/$/, '' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/$/, '' ) + path;
		}
		Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
		$display.addClass( 'spx-location-control__display--loading' );

		return window.fetch( url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'request_failed' ); } return r.json(); } )
			.then( function ( data ) {
				if ( id !== requestId ) { return { items: [], stale: true }; } // discard stale
				$display.removeClass( 'spx-location-control__display--loading' );
				if ( $feedback ) { $feedback.text( '' ); }
				return data;
			} )
			.catch( function () {
				if ( id === requestId ) {
					$display.removeClass( 'spx-location-control__display--loading' );
					if ( $feedback ) { $feedback.text( config.labels.error ); }
				}
				return { items: [], stale: id !== requestId };
			} );
	}

	function renderItems( items, filterText ) {
		$list.empty();
		var filter = ( filterText || '' ).toLowerCase();
		var count = 0;
		items.forEach( function ( item ) {
			if ( filter && item.label.toLowerCase().indexOf( filter ) === -1 ) { return; }
			var $li = $( '<li>' )
				.addClass( 'spx-location-control__item' )
				.attr( { tabindex: '0', role: 'option', 'data-id': item.id } )
				.text( item.label );
			$list.append( $li );
			count++;
		} );
		if ( count === 0 ) {
			$list.append( $( '<li>' ).addClass( 'spx-location-control__empty' ).text( config.labels.empty || 'Không tìm thấy' ) );
		}
	}

	function updateBreadcrumb() {
		$breadcrumb.empty();
		if ( currentLevel === 'district' || currentLevel === 'ward' ) {
			var $back = $( '<button type="button">' )
				.addClass( 'spx-location-control__breadcrumb-back' )
				.attr( 'aria-label', config.labels.back || 'Quay lại' )
				.html( '&#8592; ' );
			$breadcrumb.append( $back );
			if ( currentLevel === 'district' ) {
				$breadcrumb.append( document.createTextNode( selectedProvince.label ) );
			} else {
				$breadcrumb.append( document.createTextNode( selectedProvince.label + ' › ' + selectedDistrict.label ) );
			}
			$breadcrumb.show();
		} else {
			$breadcrumb.hide();
		}
	}

	function updateDisplayText() {
		var wardLabel = $wardInput.attr( 'data-label' ) || '';
		if ( selectedProvince.id && selectedDistrict.id && wardLabel ) {
			$displayText.text( selectedProvince.label + ' - ' + selectedDistrict.label + ' - ' + wardLabel );
			$display.removeClass( 'spx-location-control__display--placeholder' );
		} else {
			$displayText.text( config.labels.choose || '— Chọn khu vực —' );
			$display.addClass( 'spx-location-control__display--placeholder' );
		}
	}

	function openPanel() {
		if ( panelOpen ) { return; }
		panelOpen = true;
		$panel.addClass( 'spx-location-control__panel--open' );
		$search.val( '' ).focus();
		loadCurrentLevel();
	}

	function closePanel() {
		panelOpen = false;
		$panel.removeClass( 'spx-location-control__panel--open' );
	}

	function loadCurrentLevel() {
		if ( currentLevel === 'province' ) {
			api( '/provinces', {} ).then( function ( data ) {
				if ( data.stale ) { return; }
				renderItems( data.items || [], '' );
				$versionInput.val( data.dataset_version || $versionInput.val() );
				updateBreadcrumb();
			} );
		} else if ( currentLevel === 'district' ) {
			api( '/districts', { province_id: selectedProvince.id } ).then( function ( data ) {
				if ( data.stale ) { return; }
				renderItems( data.items || [], '' );
				$versionInput.val( data.dataset_version || $versionInput.val() );
				updateBreadcrumb();
			} );
		} else if ( currentLevel === 'ward' ) {
			api( '/wards', { province_id: selectedProvince.id, district_id: selectedDistrict.id } ).then( function ( data ) {
				if ( data.stale ) { return; }
				var filtered = ( data.items || [] ).filter( function ( item ) {
					return item.active && item.delivery_supported;
				} );
				renderItems( filtered, '' );
				$versionInput.val( data.dataset_version || $versionInput.val() );
				updateBreadcrumb();
			} );
		}
	}

	function debouncedUpdateCheckout() {
		if ( debounceTimer ) { clearTimeout( debounceTimer ); }
		debounceTimer = setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, 300 );
	}

	function selectedMethod() {
		var selected = $( 'input[name^="shipping_method"]:checked' ).val();
		if ( ! selected ) { selected = $( 'input[name^="shipping_method"]' ).first().val(); }
		return selected || '';
	}

	function refreshVisibility() {
		// PHP owns render-time visibility: the container is only ever emitted
		// when the cart needs shipping and SPX is available in the matched zone.
		// Do NOT hide the container based on chosen-method here — before the
		// customer picks a location there is no chosen shipping method yet, so
		// hiding on that basis creates a chicken/egg where the field never
		// appears. This function is kept as a no-op for backward-compat.
	}

	// ── Bind ──────────────────────────────────────────────────────────
	function bind() {
		$control = $( '#spx-location-control' );
		if ( ! $control.length ) { return; }

		$display    = $control.find( '.spx-location-control__display' );
		$displayText = $control.find( '.spx-location-control__display-text' );
		$panel      = $control.find( '.spx-location-control__panel' );
		$search     = $control.find( '.spx-location-control__search' );
		$list       = $control.find( '.spx-location-control__list' );
		$breadcrumb = $control.find( '.spx-location-control__breadcrumb' );
		$provinceInput = $control.find( 'input[name="spx_shipping_province_id"]' );
		$districtInput = $control.find( 'input[name="spx_shipping_district_id"]' );
		$wardInput     = $control.find( 'input[name="spx_shipping_ward_id"]' );
		$versionInput  = $control.find( 'input[name="spx_shipping_dataset_version"]' );
		$feedback      = $control.find( '.spx-checkout-notice' );

		// Restore state from hidden inputs.
		if ( $provinceInput.val() && $districtInput.val() && $wardInput.val() ) {
			selectedProvince = { id: $provinceInput.val(), label: $provinceInput.attr( 'data-label' ) || '' };
			selectedDistrict = { id: $districtInput.val(), label: $districtInput.attr( 'data-label' ) || '' };
		}
		updateDisplayText();
		refreshVisibility();

		// Unbind SPX-namespaced events before rebinding.
		$display.off( '.spxCheckout' );
		$search.off( '.spxCheckout' );
		$list.off( '.spxCheckout' );
		$breadcrumb.off( '.spxCheckout' );

		$display.on( 'click.spxCheckout', function ( e ) {
			e.preventDefault();
			if ( panelOpen ) { closePanel(); } else { openPanel(); }
		} );

		$display.on( 'keydown.spxCheckout', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); openPanel(); }
			if ( e.key === 'Escape' ) { closePanel(); }
		} );

		$search.on( 'input.spxCheckout', function () {
			var filterText = $( this ).val();
			$list.find( '.spx-location-control__item' ).each( function () {
				var match = $( this ).text().toLowerCase().indexOf( filterText.toLowerCase() ) !== -1;
				$( this ).toggle( match );
			} );
		} );

		$list.on( 'click.spxCheckout', '.spx-location-control__item', function () {
			var id = $( this ).attr( 'data-id' );
			var label = $( this ).text();
			selectItem( id, label );
		} );

		$list.on( 'keydown.spxCheckout', '.spx-location-control__item', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				$( this ).trigger( 'click' );
			}
		} );

		$breadcrumb.on( 'click.spxCheckout', '.spx-location-control__breadcrumb-back', function () {
			goBack();
		} );
	}

	function selectItem( id, label ) {
		$search.val( '' );
		if ( currentLevel === 'province' ) {
			selectedProvince = { id: id, label: label };
			selectedDistrict = { id: '', label: '' };
			$provinceInput.val( id ).attr( 'data-label', label );
			$districtInput.val( '' ).attr( 'data-label', '' );
			$wardInput.val( '' ).attr( 'data-label', '' );
			currentLevel = 'district';
			loadCurrentLevel();
		} else if ( currentLevel === 'district' ) {
			selectedDistrict = { id: id, label: label };
			$districtInput.val( id ).attr( 'data-label', label );
			$wardInput.val( '' ).attr( 'data-label', '' );
			currentLevel = 'ward';
			loadCurrentLevel();
		} else if ( currentLevel === 'ward' ) {
			$wardInput.val( id ).attr( 'data-label', label );
			closePanel();
			currentLevel = 'province';
			updateDisplayText();
			debouncedUpdateCheckout();
		}
	}

	function goBack() {
		$search.val( '' );
		if ( currentLevel === 'ward' ) {
			selectedDistrict = { id: '', label: '' };
			$districtInput.val( '' ).attr( 'data-label', '' );
			$wardInput.val( '' ).attr( 'data-label', '' );
			currentLevel = 'district';
			loadCurrentLevel();
		} else if ( currentLevel === 'district' ) {
			selectedProvince = { id: '', label: '' };
			$provinceInput.val( '' ).attr( 'data-label', '' );
			$districtInput.val( '' ).attr( 'data-label', '' );
			$wardInput.val( '' ).attr( 'data-label', '' );
			currentLevel = 'province';
			loadCurrentLevel();
		}
		updateDisplayText();
	}

	// ── Close on outside click ────────────────────────────────────────
	$( document ).on( 'click.spxCheckout', function ( e ) {
		if ( panelOpen && $control && ! $control[ 0 ].contains( e.target ) ) {
			closePanel();
		}
	} );

	// ── WooCommerce events ────────────────────────────────────────────
	$( document.body ).on( 'updated_checkout', function () {
		currentLevel = 'province'; // reset navigation, keep selection
		bind();
	} );
	$( document.body ).on( 'change', 'input[name^="shipping_method"]', refreshVisibility );
	$( bind );

}( window.jQuery, window.spxCheckoutAddress ) );
