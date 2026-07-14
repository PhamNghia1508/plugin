( function ( $, config ) {
	'use strict';
	if ( ! config ) { return; }

	var $section, $province, $district, $ward, $version, $feedback;

	function option( id, label ) {
		return $( '<option>' ).val( id ).text( label );
	}

	function reset( $select, label ) {
		$select.empty().append( option( '', label || config.labels.choose ) ).prop( 'disabled', false );
	}

	function request( path, params ) {
		var url = new window.URL( config.restUrl, window.location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/$/, '' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/$/, '' ) + path;
		}
		Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
		$feedback.text( config.labels.loading );
		return window.fetch( url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( response ) { if ( ! response.ok ) { throw new Error( 'request_failed' ); } return response.json(); } )
			.then( function ( data ) { $feedback.text( '' ); return data; } )
			.catch( function () { $feedback.text( config.labels.error ); return { items: [] }; } );
	}

	function selectedMethod() {
		var selected = $( 'input[name^="shipping_method"]:checked' ).val();
		if ( ! selected ) { selected = $( 'input[name^="shipping_method"]' ).first().val(); }
		return selected || '';
	}

	function refreshVisibility() {
		var required = selectedMethod().split( ':' )[0] === config.spxMethod;
		$section.toggle( required ).attr( 'data-spx-required', required ? '1' : '0' );
		$section.find( 'select' ).prop( 'required', required );
	}

	function bind() {
		$section = $( '#spx-shipping-address' );
		if ( ! $section.length ) { return; }
		$province = $( '#spx_shipping_province_id' );
		$district = $( '#spx_shipping_district_id' );
		$ward = $( '#spx_shipping_ward_id' );
		$version = $( 'input[name="spx_shipping_dataset_version"]' );
		$feedback = $section.find( '.spx-shipping-address__feedback' );

		$province.off( '.spx' ).on( 'change.spx', function () {
			reset( $district ); reset( $ward );
			if ( ! this.value ) { return; }
			request( '/districts', { province_id: this.value } ).then( function ( data ) {
				$version.val( data.dataset_version || $version.val() );
				data.items.forEach( function ( item ) { $district.append( option( item.id, item.label ) ); } );
			} );
		} );
		$district.off( '.spx' ).on( 'change.spx', function () {
			reset( $ward );
			if ( ! this.value || ! $province.val() ) { return; }
			request( '/wards', { province_id: $province.val(), district_id: this.value } ).then( function ( data ) {
				$version.val( data.dataset_version || $version.val() );
				data.items.forEach( function ( item ) {
					if ( item.active && item.delivery_supported ) { $ward.append( option( item.id, item.label ) ); }
				} );
			} );
		} );
		$ward.off( '.spx' ).on( 'change.spx', function () {
			$( document.body ).trigger( 'update_checkout' );
		} );
		refreshVisibility();
	}

	$( document.body ).on( 'updated_checkout', bind );
	$( document.body ).on( 'change', 'input[name^="shipping_method"]', refreshVisibility );
	$( bind );
}( window.jQuery, window.spxCheckoutAddress ) );
