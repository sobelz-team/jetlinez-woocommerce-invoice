/* Run with node tests/admin-discovery-smoke.js. No browser packages required. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8');

class Element {
	constructor(value = '') {
		this.value = value;
		this.listeners = {};
		this.options = [];
		this.disabled = false;
		this.checked = false;
	}
	addEventListener(name, callback) { this.listeners[name] = callback; }
	add(option) { this.options.push(option); }
	replaceChildren(...options) { this.options = options; this.value = ''; }
	emit(name) { return this.listeners[name](); }
}

function setup(hasApiKey = true) {
	const elements = {};
	['refresh-destinations', 'api-base-url', 'api-key', 'send-method', 'discovery-status', 'device-id', 'telegram-device-id', 'flow-id'].forEach(id => {
		elements[id] = new Element();
	});
	elements['api-base-url'].value = 'https://my.jetlinez.com/api/v1';
	elements['send-method'].value = 'whatsapp';
	elements['device-id'].value = 'wa-old';
	const clearKey = new Element();
	const rows = ['whatsapp', 'telegram', 'flow'].map(method => ({ dataset: { jlwiMethod: method } }));
	const pending = [];
	const config = { hasApiKey, url: '/ajax', nonce: 'nonce', loading: 'loading', loaded: 'loaded', failed: 'failed', needKey: 'need-key', choose: 'choose', empty: 'empty', missing: 'missing', devices: 'devices', flows: 'flows' };
	vm.runInNewContext(source, {
		window: { jlwiConnection: config },
		document: {
			getElementById: id => elements[id.replace('jlwi-', '')],
			querySelector: () => clearKey,
			querySelectorAll: () => rows
		},
		Option: class { constructor(text, value) { this.text = text; this.value = value; } },
		URLSearchParams, AbortController,
		fetch: (url, args) => new Promise(resolve => pending.push({ url, args, resolve }))
	});
	return { elements, clearKey, rows, pending };
}

async function answer(request, data, success = true) {
	request.resolve({ ok: true, json: async () => ({ success, data }) });
	await new Promise(resolve => setImmediate(resolve));
}

(async function () {
	const { elements: e, rows, pending } = setup();
	assert.equal(pending.length, 1, 'Saved credentials should trigger automatic discovery.');
	assert.equal(pending[0].args.body.get('api_key'), '', 'Saved secrets must stay on the server.');
	assert.equal(pending[0].args.body.get('_ajax_nonce'), 'nonce');
	assert.deepEqual(rows.map(row => row.hidden), [false, true, true]);
	await answer(pending[0], {
		devices: [{ id: 'wa-old', name: 'WA', channel: 'whatsapp' }, { id: 'tg', name: 'TG', channel: 'telegram' }],
		flows: [{ id: 'f', name: 'Flow' }], errors: {}
	});
	assert.equal(e['device-id'].value, 'wa-old', 'Refresh lost the selected device.');
	assert.equal(e['device-id'].options.length, 2, 'WhatsApp list must exclude Telegram devices.');
	assert.equal(e['telegram-device-id'].options[1].value, 'tg');
	assert.equal(e['flow-id'].options[1].value, 'f');
	assert.equal(e['flow-id'].value, '', 'Discovery must not choose a destination for the user.');
	e['send-method'].value = 'flow';
	e['send-method'].emit('change');
	assert.deepEqual(rows.map(row => row.hidden), [true, true, false]);

	// A failure for one resource preserves its selections while updating the other.
	e['refresh-destinations'].emit('click');
	await answer(pending[1], { devices: [], flows: [], errors: { devices: 'forbidden' } });
	assert.equal(e['device-id'].value, 'wa-old');
	assert.equal(e['device-id'].options.length, 2);
	assert.equal(e['discovery-status'].textContent, 'devices: forbidden');
	assert.equal(e['refresh-destinations'].disabled, false);

	// Credential edits cancel earlier requests, clear stale choices and ignore late results.
	e['refresh-destinations'].emit('click');
	e['api-key'].value = 'new-key';
	e['api-key'].emit('input');
	assert.equal(pending[2].args.signal.aborted, true);
	assert.equal(e['device-id'].value, '');
	e['api-key'].emit('change');
	assert.equal(pending[3].args.body.get('api_key'), 'new-key');
	await answer(pending[3], { devices: [], flows: [{ id: 'new-flow', name: '<unsafe>' }], errors: {} });
	await answer(pending[2], { devices: [], flows: [{ id: 'stale', name: 'stale' }], errors: {} });
	assert.equal(e['flow-id'].options[1].value, 'new-flow', 'An older response replaced the new account list.');
	assert.equal(e['discovery-status'].textContent, 'loaded');

	const fresh = setup(false);
	assert.equal(fresh.pending.length, 0, 'Discovery ran without any API key.');
	fresh.elements['api-key'].value = 'entered';
	fresh.elements['api-key'].emit('change');
	assert.equal(fresh.pending.length, 1, 'Entering a key should trigger discovery.');
	await answer(fresh.pending[0], { message: 'Invalid key' }, false);
	assert.equal(fresh.elements['discovery-status'].textContent, 'Invalid key');
	fresh.clearKey.checked = true;
	fresh.clearKey.emit('change');
	fresh.elements['refresh-destinations'].emit('click');
	assert.equal(fresh.pending.length, 1, 'Clear-key mode must not query with the saved credential.');
	console.log('Admin discovery smoke test passed.');
}()).catch(error => { console.error(error); process.exitCode = 1; });
