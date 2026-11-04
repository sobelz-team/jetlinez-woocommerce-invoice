(function () {
	'use strict';

	const config = window.jlwiConnection;
	const refresh = document.getElementById('jlwi-refresh-destinations');
	if (!config || !refresh) return;

	const base = document.getElementById('jlwi-api-base-url');
	const key = document.getElementById('jlwi-api-key');
	const clearKey = document.querySelector('[name="jlwi[clear_api_key]"]');
	const method = document.getElementById('jlwi-send-method');
	const status = document.getElementById('jlwi-discovery-status');
	const selects = {
		whatsapp: document.getElementById('jlwi-device-id'),
		telegram: document.getElementById('jlwi-telegram-device-id'),
		flow: document.getElementById('jlwi-flow-id')
	};
	let controller;
	let generation = 0;

	function showMethod() {
		document.querySelectorAll('[data-jlwi-method]').forEach(function (row) {
			row.hidden = row.dataset.jlwiMethod !== method.value;
		});
	}

	function populate(select, items) {
		const previous = select.value;
		select.replaceChildren(new Option(items.length ? config.choose : config.empty, ''));
		items.forEach(function (item) {
			select.add(new Option(item.name + ' — ' + item.id, item.id));
		});
		if (previous && !items.some(function (item) { return item.id === previous; })) {
			select.add(new Option(config.missing + ' — ' + previous, previous));
		}
		select.value = previous;
	}

	function cancelRequest() {
		generation += 1;
		if (controller) controller.abort();
		refresh.disabled = false;
	}

	function credentialsChanged() {
		cancelRequest();
		Object.values(selects).forEach(function (select) {
			if (!select.disabled) {
				select.value = '';
				populate(select, []);
			}
		});
		status.textContent = config.needKey;
	}

	async function discover() {
		cancelRequest();
		if ((clearKey && clearKey.checked) || (!key.value.trim() && !config.hasApiKey)) {
			status.textContent = config.needKey;
			return;
		}
		const current = generation;
		controller = new AbortController();
		const body = new URLSearchParams({
			action: 'jlwi_discover_destinations',
			_ajax_nonce: config.nonce,
			api_base_url: base.value,
			api_key: key.value
		});
		refresh.disabled = true;
		status.textContent = config.loading;
		try {
			const response = await fetch(config.url, {
				method: 'POST', body: body, credentials: 'same-origin', signal: controller.signal
			});
			const result = await response.json();
			if (current !== generation) return;
			if (!response.ok || !result.success) {
				throw new Error(result.data && result.data.message ? result.data.message : config.failed);
			}
			const data = result.data;
			const errors = data.errors || {};
			if (!errors.devices) {
				['whatsapp', 'telegram'].forEach(function (channel) {
					populate(selects[channel], data.devices.filter(function (device) { return device.channel === channel; }));
				});
			}
			if (!errors.flows) populate(selects.flow, data.flows);
			status.textContent = Object.keys(errors).length
				? Object.keys(errors).map(function (type) { return config[type] + ': ' + errors[type]; }).join(' | ')
				: config.loaded;
		} catch (error) {
			if (current === generation && error.name !== 'AbortError') status.textContent = error.message || config.failed;
		} finally {
			if (current === generation) refresh.disabled = false;
		}
	}

	[key, base].forEach(function (input) {
		input.addEventListener('input', credentialsChanged);
		input.addEventListener('change', discover);
	});
	if (clearKey) clearKey.addEventListener('change', function () {
		credentialsChanged();
		if (!clearKey.checked) discover();
	});
	method.addEventListener('change', showMethod);
	refresh.addEventListener('click', discover);
	showMethod();
	if (config.hasApiKey) discover();
}());
