/**
 * SuperShip checkout address cascade: Tỉnh/Thành -> Quận/Huyện -> Phường/Xã.
 *
 * billing_state (province) is rendered server-side with the full option
 * list already (small, ~63 items). billing_city (district) and
 * billing_commune are rendered empty and populated here via SuperShip's
 * own Areas API, proxied through this plugin's REST endpoints
 * (class-supership-checkout-areas-rest-controller.php) so the values the
 * customer picks are guaranteed to match what SuperShip's order-creation
 * API expects.
 *
 * Targets the billing_* fields only - the "ship to a different address"
 * section is removed entirely (see SuperShip_Checkout_Address_Fields::
 * remove_shipping_address_section()), so billing IS the delivery address.
 */
( function ( $, config ) {
	'use strict';

	if ( ! config || ! config.restUrl ) {
		return;
	}

	function apiUrl( path ) {
		return config.restUrl.replace( /\/$/, '' ) + path;
	}

	function fetchItems( path, params ) {
		return $.getJSON( apiUrl( path ), params )
			.then( function ( data ) {
				return ( data && Array.isArray( data.items ) ) ? data.items : [];
			} )
			.catch( function () {
				return [];
			} );
	}

	function populateSelect( $select, items, placeholder, selectedValue ) {
		$select.empty();
		$select.append( $( '<option>', { value: '', text: placeholder } ) );
		items.forEach( function ( item ) {
			$select.append( $( '<option>', { value: item.name, text: item.name } ) );
		} );
		if ( selectedValue ) {
			$select.val( selectedValue );
		}
	}

	function loadDistricts( $province, $district, $commune, restoreDistrict, restoreCommune ) {
		var province = $province.val();

		populateSelect( $commune, [], config.labels.chooseCommune );

		if ( ! province ) {
			populateSelect( $district, [], config.labels.chooseDistrict );
			return;
		}

		populateSelect( $district, [], config.labels.loading );

		fetchItems( '/areas/districts', { province: province } ).then( function ( items ) {
			populateSelect( $district, items, config.labels.chooseDistrict, restoreDistrict );

			if ( restoreDistrict && $district.val() === restoreDistrict ) {
				loadCommunes( $province, $district, $commune, restoreCommune );
			}
		} );
	}

	function loadCommunes( $province, $district, $commune, restoreCommune ) {
		var province = $province.val();
		var district = $district.val();

		if ( ! province || ! district ) {
			populateSelect( $commune, [], config.labels.chooseCommune );
			return;
		}

		populateSelect( $commune, [], config.labels.loading );

		fetchItems( '/areas/communes', { province: province, district: district } ).then( function ( items ) {
			populateSelect( $commune, items, config.labels.chooseCommune, restoreCommune );
		} );
	}

	function bind() {
		var $province = $( '#billing_state' );
		var $district = $( '#billing_city' );
		var $commune  = $( '#billing_commune' );

		if ( ! $province.length || ! $district.length || ! $commune.length ) {
			return;
		}
		if ( $province.data( 'supershipBound' ) ) {
			return; // Already bound - WooCommerce may re-trigger this on partial refreshes.
		}
		$province.data( 'supershipBound', true );

		var restoreDistrict = $district.attr( 'data-supership-saved' ) || '';
		var restoreCommune  = $commune.attr( 'data-supership-saved' ) || '';

		$province.on( 'change', function () {
			loadDistricts( $province, $district, $commune, '', '' );
			$( document.body ).trigger( 'update_checkout' );
		} );

		$district.on( 'change', function () {
			loadCommunes( $province, $district, $commune, '' );
			$( document.body ).trigger( 'update_checkout' );
		} );

		$commune.on( 'change', function () {
			$( document.body ).trigger( 'update_checkout' );
		} );

		// Restore cascade on first render (e.g. after a failed checkout validation re-render).
		if ( $province.val() ) {
			loadDistricts( $province, $district, $commune, restoreDistrict, restoreCommune );
		}
	}

	// WooCommerce replaces the checkout fields markup on 'updated_checkout' -
	// re-bind each time since old elements/handlers are gone.
	$( document.body ).on( 'updated_checkout', bind );
	$( bind );

}( window.jQuery, window.supershipCheckoutAddress ) );
