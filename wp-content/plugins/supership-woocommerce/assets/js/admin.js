(function () {
	'use strict';

	function fill(select, items) {
		select.replaceChildren(new Option('—', ''));
		items.forEach(function (item) {
			select.add(new Option(item.name, item.code));
		});
	}

	function request(action, data) {
		var body = new URLSearchParams({ action: action, nonce: window.spxAdmin.nonce });
		Object.keys(data).forEach(function (key) { body.set(key, data[key]); });
		return window.fetch(window.spxAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString()
		}).then(function (response) { return response.json(); });
	}

	function bindLocationPicker(container) {
		var province = container.querySelector('select[name$="province_code"]');
		var district = container.querySelector('select[name$="district_code"]');
		var ward = container.querySelector('select[name$="ward_code"]');
		if (!province || !district || !ward) { return; }

		province.addEventListener('change', function () {
			fill(district, []);
			fill(ward, []);
			if (!province.value) { return; }
			request('spx_get_districts', { province_code: province.value }).then(function (result) {
				if (result && result.success && Array.isArray(result.data)) { fill(district, result.data); }
			});
		});

		district.addEventListener('change', function () {
			fill(ward, []);
			if (!district.value) { return; }
			request('spx_get_wards', { district_code: district.value }).then(function (result) {
				if (result && result.success && Array.isArray(result.data)) { fill(ward, result.data); }
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-spx-confirm]').forEach(function (control) {
			control.addEventListener('click', function (event) {
				if (!window.confirm(control.getAttribute('data-spx-confirm'))) { event.preventDefault(); }
			});
		});

		if (!window.spxAdmin || !window.spxAdmin.ajaxUrl || !window.spxAdmin.nonce) { return; }
		document.querySelectorAll('[data-spx-location-picker]').forEach(bindLocationPicker);
	});
}());
