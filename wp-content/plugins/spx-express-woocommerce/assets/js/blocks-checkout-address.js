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

	function SelectField( props ) {
		return el( 'label', { className: 'spx-checkout-block__field' },
			el( 'span', null, props.label, el( 'abbr', { title: 'bắt buộc' }, '*' ) ),
			el( 'select', { value: props.value, onChange: function ( event ) { props.onChange( event.target.value ); }, required: true, disabled: props.disabled },
				el( 'option', { value: '' }, chooseLabel ),
				props.items.map( function ( item ) { return el( 'option', { key: item.id, value: item.id }, item.label ); } )
			)
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
		var province = provinceState[0], setProvince = provinceState[1];
		var district = districtState[0], setDistrict = districtState[1];
		var ward = wardState[0], setWard = wardState[1];
		var version = versionState[0], setVersion = versionState[1];
		var provinces = provincesState[0], setProvinces = provincesState[1];
		var districts = districtsState[0], setDistricts = districtsState[1];
		var wards = wardsState[0], setWards = wardsState[1];
		var error = errorState[0], setError = errorState[1];
		var setExtensionData = props.checkoutExtensionData && props.checkoutExtensionData.setExtensionData;

		var cart = props.cart || {};
		var isSpx = hasSelectedSpx( cart.shippingRates || cart.shipping_rates || [] );

		useEffect( function () {
			fetchItems( '/provinces', {} ).then( function ( data ) { setProvinces( data.items || [] ); setVersion( data.dataset_version || version ); } ).catch( function () { setError( 'Không thể tải địa chỉ SPX.' ); } );
		}, [] );
		useEffect( function () {
			if ( ! province ) { setDistricts( [] ); return; }
			fetchItems( '/districts', { province_id: province } ).then( function ( data ) { setDistricts( data.items || [] ); setVersion( data.dataset_version || version ); } ).catch( function () { setError( 'Không thể tải Quận/Huyện SPX.' ); } );
		}, [ province ] );
		useEffect( function () {
			if ( ! province || ! district ) { setWards( [] ); return; }
			fetchItems( '/wards', { province_id: province, district_id: district } ).then( function ( data ) {
				setWards( ( data.items || [] ).filter( function ( item ) { return item.active && item.delivery_supported; } ) );
				setVersion( data.dataset_version || version );
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

		if ( ! isSpx ) { return null; }
		return el( 'section', { className: 'spx-shipping-address spx-checkout-block' },
			el( 'h3', null, 'Địa chỉ giao hàng SPX' ),
			el( 'p', { className: 'spx-shipping-address__note' }, 'Chỉ dùng để xác định khu vực giao hàng SPX; địa chỉ WooCommerce của bạn vẫn được giữ nguyên.' ),
			el( SelectField, { label: 'Tỉnh/Thành phố SPX', value: province, items: provinces, onChange: function ( id ) { setProvince( id ); setDistrict( '' ); setWard( '' ); setError( '' ); } } ),
			el( SelectField, { label: 'Quận/Huyện SPX', value: district, items: districts, disabled: ! province, onChange: function ( id ) { setDistrict( id ); setWard( '' ); setError( '' ); } } ),
			el( SelectField, { label: 'Phường/Xã SPX', value: ward, items: wards, disabled: ! district, onChange: function ( id ) { setWard( id ); setError( '' ); } } ),
			el( 'p', { className: 'spx-shipping-address__feedback', role: 'status', 'aria-live': 'polite' }, error )
		);
	}

	var metadata = {
		apiVersion: 3,
		name: 'spx-express/shipping-address',
		version: '0.9.0-rc.2',
		title: 'Địa chỉ giao hàng SPX',
		category: 'woocommerce',
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
