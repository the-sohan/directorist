/* Focused contracts for the directly shipped MCP UI. Run with node --test. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const assetRoot = path.resolve(__dirname, '../../assets/src/js/admin/mcp');
const files = ['services/api-client.js', 'utilities/snippets.js', 'utilities/clipboard.js', 'utilities/modal-accessibility.js', 'components/client-configuration.js', 'components/connection-drawer.js', 'components/configuration-field.js', 'settings-panel-adapter.js'];
function runtime(overrides = {}) {
  const window = { isSecureContext: true, addEventListener() {}, removeEventListener() {} };
  const context = vm.createContext({ window, TextEncoder, btoa, AbortController, FormData, Set, console, ...overrides });
  for (const file of files) vm.runInContext(fs.readFileSync(path.join(assetRoot, file), 'utf8'), context, { filename: file });
  return { mcp: window.DirectoristMcp, window, context };
}
function instance(component, extra = {}) {
  const object = { ...extra };
  Object.assign(object, component.data ? component.data.call(object) : {});
  for (const [key, method] of Object.entries(component.methods || {})) object[key] = method.bind(object);
  for (const [key, getter] of Object.entries(component.computed || {})) Object.defineProperty(object, key, { get: () => getter.call(object) });
  return object;
}

test('snippet generation handles UTF-8 and quoted JSON without retaining credentials', () => {
  const { mcp } = runtime();
  const selectedClient = { snippet: JSON.stringify({ user: '<username>', password: '<application-password>', args: ['Basic <base64(username:application-password)>'] }) };
  const inputs = { opened: true, ready: true, enabled: true, selectedClient, username: ' ব্যবহারকারী ', password: ' quote"\\value ' };
  const result = JSON.parse(mcp.buildSnippet(inputs));
  assert.equal(result.user, 'ব্যবহারকারী');
  assert.equal(result.password, 'quote"\\value');
  assert.equal(result.args[0], 'Basic ' + Buffer.from('ব্যবহারকারী:quote"\\value').toString('base64'));
  assert.equal(mcp.buildSnippet({ ...inputs, opened: false }), '');
  assert.equal(mcp.buildSnippet({ ...inputs, enabled: false }), '');
  assert.equal(mcp.buildSnippet({ ...inputs, ready: false }), '');
  assert.equal(mcp.buildSnippet({ ...inputs, password: '' }), selectedClient.snippet);
  assert.equal(Object.values(mcp).every((value) => typeof value === 'function'), true);
});

test('API requests retain nonce/auth behavior, close on revocation, and cancel outstanding work', async () => {
  let seen;
  let unauthorized = 0;
  const { mcp, context } = runtime({ fetch: async (url, options) => { seen = options; return { ok: false, status: 403, json: async () => ({ message: 'Revoked' }) }; } });
  const api = mcp.createApiClient({ nonce: 'nonce-fixture' }, () => unauthorized++);
  await assert.rejects(api.request('/status'), /Revoked/);
  assert.equal(seen.headers['X-WP-Nonce'], 'nonce-fixture');
  assert.equal(seen.credentials, 'same-origin');
  assert.equal(unauthorized, 1);
  context.fetch = (url, options) => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('Stopped', 'AbortError'))));
  const pending = api.request('/templates');
  api.abort();
  await assert.rejects(pending, { name: 'AbortError' });
});

test('authentication cleanup runs for non-JSON failures without exposing response content', async () => {
  for (const status of [401, 403]) {
    let cleared = false;
    const { mcp } = runtime({ fetch: async () => ({ ok: false, status, json: async () => { throw new SyntaxError('Private proxy response'); } }) });
    await assert.rejects(mcp.createApiClient({}, () => { cleared = true; }).request('/status'), /Sign in again/);
    assert.equal(cleared, true);
  }
  const { mcp } = runtime({ fetch: async () => ({ ok: false, status: 500, json: async () => null }) });
  await assert.rejects(mcp.createApiClient({}, () => {}).request('/status'), /The request could not be completed/);
});

test('field saves only the enablement key through native AJAX and updates confirmed Vuex state', async () => {
  const { mcp } = runtime();
  const commits = [];
  let body;
  const field = instance(mcp.createConfigurationField({ ajaxUrl: '/save', ajaxNonce: 'fixture' }), {
    value: false, fieldKey: 'mcp_enabled', _alive: true,
    $store: { commit: (...args) => commits.push(args) },
    _api: { async request(url, options) { body = options.body; return { status: { success: true } }; } },
  });
  field.refresh = async () => { field.persist(true); return { enabled: true }; };
  await field.toggle(true);
  assert.equal(body.get('action'), 'save_settings_data');
  assert.equal(body.get('directorist_nonce'), 'fixture');
  assert.deepEqual(JSON.parse(body.get('field_list')), ['mcp_enabled']);
  assert.equal(body.get('mcp_enabled'), 'true');
  assert.equal([...body.keys()].length, 4);
  assert.equal(field.enabled, true);
  assert.equal(field.saving, false);
  assert.equal(commits[0][0], 'setPersistedAsyncField');
  assert.equal(commits[1][0], 'updateFieldData');
});

test('a rejected save keeps the checkbox and card at their confirmed state', async () => {
  const { mcp } = runtime();
  for (const enabled of [true, false]) {
    const input = { checked: !enabled };
    const field = instance(mcp.createConfigurationField({ ajaxUrl: '/save' }), {
      value: enabled, _alive: true,
      _api: { async request() { return { status: { success: false, status_log: { message: 'Permission revoked' } } }; } },
    });
    const pending = field.changeEnabled({ target: input });
    assert.equal(input.checked, enabled);
    await pending;
    assert.equal(input.checked, enabled);
    assert.equal(field.enabled, enabled);
    assert.equal(field.error, 'Permission revoked');
    assert.equal(field.saving, false);
  }
});

test('a completed save cannot start new requests after the field is destroyed', async () => {
  const { mcp } = runtime();
  let resolve;
  let requests = 0;
  const component = mcp.createConfigurationField({ ajaxUrl: '/save' });
  const field = instance(component, {
    value: false, _alive: true,
    _api: { request() { requests++; return requests === 1 ? new Promise((done) => { resolve = done; }) : Promise.resolve({ enabled: true }); }, abort() {} },
  });
  const pending = field.toggle(true);
  component.beforeDestroy.call(field);
  resolve({ status: { success: true } });
  await pending;
  assert.equal(requests, 1);
  assert.equal(field.message, '');
});

test('drawer cleanup clears transient credentials, cancels requests and restores modal state', () => {
  const { mcp } = runtime();
  const component = mcp.createConnectionDrawer({});
  let aborted = false;
  let cleaned = false;
  const drawer = instance(component, { status: { ready: true }, enabled: true });
  drawer.password = 'disposable-fixture';
  drawer.username = 'fixture';
  drawer.clients = [{ id: 'fixture', snippet: 'fixture' }];
  drawer._api = { abort() { aborted = true; } };
  drawer._modal = { close() { cleaned = true; } };
  component.beforeDestroy.call(drawer);
  assert.equal(drawer.password, '');
  assert.equal(drawer.username, '');
  assert.equal(drawer.clients.length, 0);
  assert.equal(drawer.opened, false);
  assert.equal(aborted && cleaned, true);
});

test('late template responses do not repopulate a destroyed drawer', async () => {
  const { mcp } = runtime();
  let resolve;
  const drawer = instance(mcp.createConnectionDrawer({ templatesUrl: '/templates' }), { status: { ready: true }, enabled: true, _alive: true });
  drawer._api = { request: () => new Promise((done) => { resolve = done; }), abort() {} };
  drawer._modal = { close() {} };
  const pending = drawer.loadTemplates();
  mcp.createConnectionDrawer({}).beforeDestroy.call(drawer);
  resolve({ clients: [{ id: 'late' }] });
  await pending;
  assert.equal(drawer.clients.length, 0);
  assert.equal(drawer.password, '');
});

test('clipboard fallback removes its transient text on success and failure', async () => {
  let removed = 0;
  let temporary;
  const { mcp } = runtime({ navigator: {}, document: { createElement() { return temporary = { value: '', setAttribute() {}, select() {}, remove() { removed++; } }; }, execCommand() { return false; } } });
  await assert.rejects(mcp.copyText('dummy', { appendChild() {} }));
  assert.equal(temporary.value, '');
  assert.equal(removed, 1);
});

test('modal cleanup preserves existing inert state and restores body scrolling', () => {
  const attributes = new Set(['inert']);
  const existing = { tagName: 'MAIN', hasAttribute: (key) => attributes.has(key), setAttribute: (key) => attributes.add(key), removeAttribute: (key) => attributes.delete(key) };
  const added = new Set();
  const sibling = { tagName: 'MAIN', hasAttribute: (key) => added.has(key), setAttribute: (key) => added.add(key), removeAttribute: (key) => added.delete(key) };
  const body = { children: [existing, sibling], style: { overflow: 'scroll' }, appendChild(element) { element.parentNode = body; } };
  const overlay = { remove() { this.parentNode = null; } };
  const { mcp } = runtime({ document: { body } });
  const modal = mcp.createModalAccessibility();
  let focused = false;
  modal.open(overlay, { focus() { focused = true; } });
  assert.equal(body.style.overflow, 'hidden');
  modal.close();
  assert.equal(body.style.overflow, 'scroll');
  assert.equal(attributes.has('inert'), true);
  assert.equal(added.size, 0);
  assert.equal(focused, true);
});

test('modal focus includes native disclosures and excludes inactive client tabs', () => {
  let focused;
  const node = (name, tabIndex = 0) => ({ tabIndex, getClientRects: () => [1], focus() { focused = name; } });
  const close = node('close');
  const copy = node('copy');
  const summary = node('verify');
  const inactive = node('inactive', -1);
  const dialog = { querySelectorAll: (selector) => selector.includes('summary') ? [close, copy, summary, inactive] : [close, copy, inactive] };
  let prevented = false;
  const key = (shiftKey = false) => ({ key: 'Tab', shiftKey, preventDefault() { prevented = true; } });
  // The Copy button must allow normal forward navigation to the disclosure.
  const document = { activeElement: copy };
  const context = runtime({ document });
  const helper = context.mcp.createModalAccessibility();
  helper.keydown(key(), dialog, () => {});
  assert.equal(prevented, false);
  document.activeElement = summary;
  helper.keydown(key(), dialog, () => {});
  assert.equal(focused, 'close');
  document.activeElement = close;
  helper.keydown(key(true), dialog, () => {});
  assert.equal(focused, 'verify');
});

test('keyboard tab navigation emits selection and moves focus after rendering', () => {
  const { mcp } = runtime();
  const events = [];
  let focused = false;
  const tabs = instance(mcp.createClientConfiguration(), { clients: [{ id: 'a' }, { id: 'b' }], client: 'a', $emit: (...args) => events.push(args), $nextTick: (callback) => callback(), $refs: { tabs: [{}, { focus() { focused = true; } }] } });
  tabs.tabKey({ key: 'ArrowRight', preventDefault() {} }, 0);
  assert.deepEqual(events, [['select-client', 'b']]);
  assert.equal(focused, true);
});

test('settings adapter installs once and excludes only MCP from general saves', () => {
  const { mcp } = runtime();
  let mounted;
  let registered = 0;
  const registry = { registerComponent() { registered++; }, registerAfterMount(callback) { mounted = callback; } };
  mcp.install(registry, {});
  mcp.install(registry, {});
  assert.equal(registered, 1);
  const fields = { mcp_enabled: {}, sibling_setting: {} };
  const store = { state: { fields, layouts: { unrelated: { sections: { keep: { fields: ['sibling_setting'] } } } } }, commit() {} };
  const manager = { fields, getSettingsSavePayload(keys) { return keys; }, updateCurrentPage() {} };
  mounted({ $store: store, $children: [manager] }, { layouts: { other_features: { submenu: { mcp: { sections: { connection: { fields: ['mcp_enabled'] } } } } } } }, { Vue: { set: (object, key, value) => object[key] = value, delete: (object, key) => delete object[key] } });
  assert.deepEqual(Array.from(manager.getSettingsSavePayload()), ['sibling_setting']);
  assert.deepEqual(store.state.layouts.unrelated.sections.keep.fields, ['sibling_setting']);
  assert.equal(store.state.layouts.other_features.submenu.mcp.sections.connection.fields[0], 'mcp_enabled');
});
