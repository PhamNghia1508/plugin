( function ( wp, wc ) {
	'use strict';
	var blocksCheckout = window.wc && window.wc.blocksCheckout;
	if ( ! wp || ! blocksCheckout ) { return; }

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var extensionCartUpdate = blocksCheckout.extensionCartUpdate;
	var rateUpdateSequence = Promise.resolve();
	var settings = wc.wcSettings ? wc.wcSettings.getSetting( 'spx-express-shipping-address_data', {} ) : {};
	var chooseLabel = '— Chọn —';

	function hasSelectedSpx( value ) {
		if ( ! value ) { return false; }
		if ( Array.isArray( value ) ) { return value.some( hasSelectedSpx ); }
		if ( typeof value !== 'object' ) { return false; }
		var id = String( value.method_id || value.rate_id || value.rateId || '' ).split( ':' )[0];
		if ( value.selected && id === 'spx_express' ) { return true; }
		return Object.keys( value ).some( function ( key ) { return hasSelectedSpx( value[ key ] ); } );
	}

	function fetchItems( path, params ) {
		var url = new window.URL( String( settings.restUrl || '' ), window.location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/$/, '' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/$/, '' ) + path;
		}
		Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
		return window.fetch( url.toString(), {
			credentials: 'same-origin', headers: { Accept: 'application/json' }
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'spx_address_fetch' ); } return response.json(); } );
	}

	function optionLabel( items, id ) {
		var match = items.filter( function ( item ) { return String( item.id ) === String( id ); } )[0];
		return match ? match.label : '';
	}

	function LocationField( props ) {
		return el( 'label', { className: 'spx-checkout-block__field' },
			el( 'span', null, 'Khu vực', el( 'abbr', { title: 'bắt buộc' }, '*' ) ),
			el( 'select', { value: '', onChange: function ( event ) { props.onChange( event.target.value ); }, required: true, disabled: props.disabled },
				el( 'option', { value: '' }, props.placeholder || chooseLabel ),
				props.items.map( function ( item ) { return el( 'option', { key: item.id, value: item.id }, item.label ); } )
			),
			props.canGoBack ? el( 'button', { type: 'button', className: 'spx-location-control__breadcrumb-back', onClick: props.onBack }, '← ', 'Quay lại' ) : null,
			props.summary ? el( 'small', { className: 'spx-location-control__summary' }, props.summary ) : null
		);
	}

	function CheckoutAddressBlock( props ) {
		var prefill = settings.prefill || {};
		var provinceState = useState( prefill.province_id || '' );
		var districtState = useState( prefill.district_id || '' );
		var wardState = useState( prefill.ward_id || '' );
		var versionState = useState( prefill.dataset_version || settings.datasetVersion || '' );
		var provincesState = useState( [] );
		var districtsState = useState( [] );
		var wardsState = useState( [] );
		var errorState = useState( '' );
		var levelState = useState( 'province' );
		var provinceLabelState = useState( '' );
		var districtLabelState = useState( '' );
		var wardLabelState = useState( '' );
		var province = provinceState[0], setProvince = provinceState[1];
		var district = districtState[0], setDistrict = districtState[1];
		var ward = wardState[0], setWard = wardState[1];
		var version = versionState[0], setVersion = versionState[1];
		var provinces = provincesState[0], setProvinces = provincesState[1];
		var districts = districtsState[0], setDistricts = districtsState[1];
		var wards = wardsState[0], setWards = wardsState[1];
		var error = errorState[0], setError = errorState[1];
		var level = levelState[0], setLevel = levelState[1];
		var provinceLabel = provinceLabelState[0], setProvinceLabel = provinceLabelState[1];
		var districtLabel = districtLabelState[0], setDistrictLabel = districtLabelState[1];
		var wardLabel = wardLabelState[0], setWardLabel = wardLabelState[1];
		var setExtensionData = props.checkoutExtensionData && props.checkoutExtensionData.setExtensionData;

		var cart = props.cart || {};
		var isSpx = hasSelectedSpx( cart.shippingRates || cart.shipping_rates || [] );

		useEffect( function () {
			fetchItems( '/provinces', {} ).then( function ( data ) {
				var items = data.items || [];
				setProvinces( items );
				setVersion( data.dataset_version || version );
				if ( province && ! provinceLabel ) { setProvinceLabel( optionLabel( items, province ) ); }
			} ).catch( function () { setError( 'Không thể tải địa chỉ SPX.' ); } );
		}, [] );
		useEffect( function () {
			if ( ! province ) { setDistricts( [] ); return; }
			fetchItems( '/districts', { province_id: province } ).then( function ( data ) {
				var items = data.items || [];
				setDistricts( items );
				setVersion( data.dataset_version || version );
				if ( district && ! districtLabel ) { setDistrictLabel( optionLabel( items, district ) ); }
			} ).catch( function () { setError( 'Không thể tải Quận/Huyện SPX.' ); } );
		}, [ province ] );
		useEffect( function () {
			if ( ! province || ! district ) { setWards( [] ); return; }
			fetchItems( '/wards', { province_id: province, district_id: district } ).then( function ( data ) {
				var items = ( data.items || [] ).filter( function ( item ) { return item.active && item.delivery_supported; } );
				setWards( items );
				setVersion( data.dataset_version || version );
				if ( ward && ! wardLabel ) { setWardLabel( optionLabel( items, ward ) ); }
			} ).catch( function () { setError( 'Không thể tải Phường/Xã SPX.' ); } );
		}, [ province, district ] );
		useEffect( function () {
			if ( typeof setExtensionData !== 'function' ) { return; }
			setExtensionData( 'spx-express', 'province_id', province );
			setExtensionData( 'spx-express', 'district_id', district );
			setExtensionData( 'spx-express', 'ward_id', ward );
			setExtensionData( 'spx-express', 'dataset_version', version );
		}, [ province, district, ward, version, setExtensionData ] );
		useEffect( function () {
			if ( ! province || ! district || ! ward || ! version || typeof extensionCartUpdate !== 'function' ) { return; }
			rateUpdateSequence = rateUpdateSequence.then( function () { return extensionCartUpdate( {
				namespace: 'spx-express',
				data: { province_id: province, district_id: district, ward_id: ward, dataset_version: version }
			} ); } ).catch( function () { setError( 'Không thể cập nhật phí giao hàng SPX.' ); } );
		}, [ province, district, ward, version ] );

		function currentItems() {
			if ( level === 'district' ) { return districts; }
			if ( level === 'ward' ) { return wards; }
			return provinces;
		}

		function currentPlaceholder() {
			if ( level === 'district' ) { return 'Chọn Quận/Huyện'; }
			if ( level === 'ward' ) { return 'Chọn Phường/Xã'; }
			return 'Chọn Tỉnh/Thành phố';
		}

		function summaryText() {
			var parts = [ provinceLabel, districtLabel, wardLabel ].filter( Boolean );
			return parts.length ? parts.join( ' - ' ) : '';
		}

		function chooseLocationPart( id ) {
			if ( ! id ) { return; }
			setError( '' );
			if ( level === 'province' ) {
				setProvince( id );
				setProvinceLabel( optionLabel( provinces, id ) );
				setDistrict( '' );
				setWard( '' );
				setDistrictLabel( '' );
				setWardLabel( '' );
				setLevel( 'district' );
				return;
			}
			if ( level === 'district' ) {
				setDistrict( id );
				setDistrictLabel( optionLabel( districts, id ) );
				setWard( '' );
				setWardLabel( '' );
				setLevel( 'ward' );
				return;
			}
			setWard( id );
			setWardLabel( optionLabel( wards, id ) );
			setLevel( 'province' );
		}

		function goBack() {
			setError( '' );
			if ( level === 'ward' ) {
				setDistrict( '' );
				setWard( '' );
				setDistrictLabel( '' );
				setWardLabel( '' );
				setLevel( 'district' );
				return;
			}
			if ( level === 'district' ) {
				setProvince( '' );
				setDistrict( '' );
				setWard( '' );
				setProvinceLabel( '' );
				setDistrictLabel( '' );
				setWardLabel( '' );
				setLevel( 'province' );
			}
		}

		if ( ! isSpx ) { return null; }
		return el( 'section', { className: 'spx-checkout-field spx-location-control spx-checkout-block' },
			el( LocationField, {
				items: currentItems(),
				placeholder: currentPlaceholder(),
				disabled: ( level === 'district' && ! province ) || ( level === 'ward' && ! district ),
				canGoBack: level === 'district' || level === 'ward',
				onBack: goBack,
				onChange: chooseLocationPart,
				summary: summaryText()
			} ),
			el( 'p', { className: 'spx-checkout-notice', role: 'alert', 'aria-live': 'polite' }, error )
		);
	}

	var metadata = {
		apiVersion: 3,
		name: 'spx-express/shipping-address',
		version: '0.9.0-rc.9',
		title: 'Địa chỉ giao hàng SPX',
		description: 'Chọn hierarchy giao hàng SPX từ dataset local.',
		parent: [ 'woocommerce/checkout-shipping-address-block' ],
		attributes: {
			lock: { type: 'object', default: { remove: true, move: true } }
		},
		supports: { html: false, align: false, multiple: false, reusable: false },
		textdomain: 'spx-express-woocommerce'
	};
	blocksCheckout.registerCheckoutBlock( { metadata: metadata, component: CheckoutAddressBlock } );
	if ( blocksCheckout.registerCheckoutFilters ) {
		blocksCheckout.registerCheckoutFilters( 'spx-express', {
			additionalCartCheckoutInnerBlockTypes: function ( blockNames ) {
				return blockNames.indexOf( metadata.name ) === -1 ? blockNames.concat( [ metadata.name ] ) : blockNames;
			}
		} );
	}
}( window.wp, window.wc ) );
