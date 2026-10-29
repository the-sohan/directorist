import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(
	new URL('../assets/src/js/react/frontend/listing-owner-dashboard/user-orders-store.js', import.meta.url),
	'utf8'
).replace(/import[\s\S]*?from\s+['"][^'"]+['"];?/g, '').replaceAll('export ', '');
let response;
let request;
let registered;
const context = {
	apiFetch: async (options) => { request = options; return response; },
	addQueryArgs: (path, params) => `${path}?${new URLSearchParams(params)}`,
	select: () => registered,
	createReduxStore: (name, config) => ({ name, config }),
	register: (store) => { registered = store; },
	getCrudConfig: () => ({ resolvers: {}, actions: { updateItem: 'preserved' } }),
};
vm.createContext(context);
vm.runInContext(`${source}\nthis.api = { normalizeUserOrders, loadUserOrders, registerUserOrdersStore };`, context);
const { normalizeUserOrders, loadUserOrders, registerUserOrdersStore } = context.api;
const items = [{ id: 101, status: 'pending' }, { id: 102, status: 'paid' }];
const headers = (total) => new Headers(total === undefined ? {} : { 'X-WP-Total': String(total) });

for (const [body, total, expectedTotal] of [
	[{ order_type: 'directorist', data: items }, 27, 27],
	[{ order_type: 'directorist', data: [] }, 0, 0],
	[{ items, total: 42 }, undefined, 42],
	[{ order_type: 'provider', data: { items, total: 32 } }, undefined, 32],
	[{ data: items }, undefined, 2],
	[{ data: items }, 'invalid', 2],
	[{ data: items }, -1, 2],
	[{ data: items }, '', 2],
	[{ data: null }, undefined, 0],
]) {
	const result = normalizeUserOrders(body, headers(total));
	assert.equal(result.total, expectedTotal);
	assert.equal(result.items.length, body.data === null ? 0 : (Array.isArray(body.data) ? body.data.length : 2));
}
assert.equal(normalizeUserOrders({ items, total: 99 }, headers(27)).total, 27);
assert.equal(normalizeUserOrders({ order_type: 'provider', data: items }, headers(2)).order_type, 'provider');

response = new Response(JSON.stringify({ order_type: 'directorist', data: items }), {
	headers: { 'X-WP-Total': '27' },
});
registerUserOrdersStore();
const firstStore = registered;
registerUserOrdersStore();
assert.equal(registered, firstStore, 'Register only once across React renders');
assert.equal(registered.name, 'directorist/user-orders', 'Keep the admin orders store separate');
assert.equal(registered.config.actions.updateItem, 'preserved');
let stored;
await registered.config.resolvers.get()({
	dispatch: { setItems: (value) => { stored = value; } },
	select: { getQueryParams: () => ({ page: 2, perPage: 10, search: 'pending' }) },
});
assert.equal(request.parse, false, 'Retain the response headers');
assert.match(request.path, /page=2&perPage=10&search=pending/);
assert.equal(stored.items[0].id, 101);
assert.equal(stored.total, 27, 'Use all-page total rather than the current page length');
context.apiFetch = async () => { throw new Error('Unauthorized'); };
await assert.rejects(loadUserOrders({}), /Unauthorized/);
console.log('PASS: new/legacy/provider responses, header totals, pagination/search, scoped store registration and fetch errors.');
