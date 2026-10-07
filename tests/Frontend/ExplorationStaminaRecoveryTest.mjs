import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/js/exploration-stamina-recovery.js', import.meta.url), 'utf8');

function harness(fetchResponse) {
    const requests = [], events = [], callbacks = new Map();
    const classes = new Set(['hidden']);
    const message = { textContent: '', classList: { toggle() {} } };
    const count = { textContent: '1' }, current = { textContent: '0' }, required = { textContent: '3' }, kiseki = { textContent: '25' };
    const close = { disabled: false, addEventListener() {}, hasAttribute: () => false };
    const item = {
        disabled: false, dataset: { itemKey: 'explore_stamina_small_bottle', useUrl: '/inventory/support-items/explore_stamina_small_bottle/use', quantity: '1' },
        hasAttribute: name => name === 'data-batch-stamina-item',
        querySelector: () => count,
        closest: selector => selector === '[data-batch-stamina-item]' ? item : null,
    };
    const buy = {
        disabled: false, dataset: { itemKey: item.dataset.itemKey, purchaseUrl: '/kiseki/support/purchase', price: '10' },
        hasAttribute: () => false,
        closest: selector => selector === '[data-batch-stamina-buy]' ? buy : null,
    };
    const home = { isConnected: true, insertBefore: element => { element.parentElement = home; } };
    const modal = {
        dataset: {}, style: {}, parentElement: home, nextSibling: null,
        classList: { contains: name => classes.has(name), add: name => classes.add(name), remove: name => classes.delete(name) },
        querySelector: selector => ({ '[data-stamina-recovery-message]': message, '[data-batch-stamina-current]': current,
            '[data-batch-stamina-required]': required, '[data-batch-stamina-kiseki]': kiseki }[selector] ?? (selector.startsWith('[data-batch-stamina-item]') ? item : null)),
        querySelectorAll: selector => selector === 'button' ? [item, buy, close] : selector === '[data-batch-stamina-item]' ? [item] : [close],
        addEventListener: (name, handler) => callbacks.set(name, handler),
    };
    const window = {
        addEventListener: (name, handler) => callbacks.set(name, handler),
        dispatchEvent: event => { events.push(event); callbacks.get(event.type)?.(event); },
    };
    const document = {
        body: { style: { overflow: 'auto' }, appendChild: element => { element.parentElement = document.body; } },
        documentElement: { style: { overflow: 'scroll' } },
        getElementById: () => modal,
        querySelector: () => ({ getAttribute: () => 'csrf-value' }),
        addEventListener: (name, handler) => callbacks.set(name, handler),
    };
    vm.runInNewContext(script, { window, document, Intl, Map, Number, String, CSS: { escape: value => value }, FormData,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        fetch: (...args) => { requests.push(args); return fetchResponse(...args); },
    });
    const click = target => callbacks.get('click')({ target, preventDefault() {}, stopPropagation() {} });
    const open = () => window.dispatchEvent({ type: 'valzeria-stamina-recovery-open', detail: { current: 0, required: 3 } });
    return { window, document, modal, item, buy, count, current, required, kiseki, message, requests, events, callbacks, classes, home, click, open };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const recovered = { success: true, message: '探索力が50回復しました。', stamina: { current: 50, max: 100, recovery_seconds: 60, next_recovery_seconds: 60 },
    support_items: [{ key: 'explore_stamina_small_bottle', quantity: 0 }] };
const response = data => ({ ok: data.success, json: async () => data });

test('nameless recovery synchronizes current stamina and counts without starting a battle', async () => {
    const app = harness(async () => response(recovered));
    app.open(); app.click(app.item); await settle();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0][0], app.item.dataset.useUrl);
    assert.equal(app.requests[0][1].body.get('_token'), 'csrf-value');
    assert.equal(app.count.textContent, '0');
    assert.equal(app.item.disabled, true);
    assert.equal(app.required.textContent, '3');
    assert.equal(app.events.find(event => event.type === 'valzeria-stamina-sync').detail.current, 50);
    assert.equal(app.classes.has('hidden'), true);
    assert.equal(app.document.body.style.overflow, 'auto');
    assert.equal(app.modal.parentElement, app.home);
});

test('ordinary exploration still resumes exactly once with its original callbacks', async () => {
    const app = harness(async () => response(recovered));
    let resumed = 0, synced = 0;
    app.window.ValzeriaStaminaRecovery.open({ id: 'explore-form' }, 0, 10, {
        sync: current => { synced = current; }, resume: async () => { resumed++; }, notify() {},
    });
    app.click(app.item); await settle();
    assert.equal(resumed, 1); assert.equal(synced, 50); assert.equal(app.required.textContent, '10');
});

test('pending use blocks duplicate clicks and closing until the response arrives', async () => {
    let finish;
    const app = harness(() => new Promise(resolve => { finish = resolve; }));
    app.open(); app.click(app.item); app.click(app.item);
    app.window.ValzeriaStaminaRecovery.close();
    assert.equal(app.requests.length, 1); assert.equal(app.classes.has('hidden'), false);
    finish(response(recovered)); await settle();
    assert.equal(app.classes.has('hidden'), true);
});

test('server rejection and lost response keep the dialog open and restore controls', async () => {
    for (const fetchResponse of [async () => response({ success: false, message: '所持していません。' }), async () => { throw new TypeError('offline'); }]) {
        const app = harness(fetchResponse); app.open(); app.click(app.item); await settle();
        assert.equal(app.classes.has('hidden'), false); assert.equal(app.item.disabled, false);
        assert.equal(app.count.textContent, '1'); assert.ok(app.message.textContent.length > 0);
        assert.equal(app.events.filter(event => event.type === 'valzeria-stamina-sync').length, 0);
    }
});

test('purchase then use retains the existing two-step API and prevents overlapping purchases', async () => {
    const app = harness(async url => response(url === '/kiseki/support/purchase'
        ? { success: true, kiseki: 15, support_items: [{ key: 'explore_stamina_small_bottle', quantity: 1 }] }
        : recovered));
    app.item.dataset.quantity = '0'; app.item.disabled = true;
    app.open(); app.click(app.buy); app.click(app.buy); await settle();
    assert.deepEqual(app.requests.map(request => request[0]), ['/kiseki/support/purchase', app.item.dataset.useUrl]);
    assert.equal(app.requests[0][1].body.get('item_key'), 'explore_stamina_small_bottle');
    assert.equal(app.kiseki.textContent, '15'); assert.equal(app.count.textContent, '0');
});

test('Escape returns the dialog to its home and restores scrolling', () => {
    const app = harness(async () => response(recovered)); app.open();
    app.callbacks.get('keydown')({ key: 'Escape' });
    assert.equal(app.classes.has('hidden'), true); assert.equal(app.modal.parentElement, app.home);
    assert.equal(app.document.documentElement.style.overflow, 'scroll');
});

test('Escape outside the recovery dialog leaves page scrolling unchanged', () => {
    const app = harness(async () => response(recovered));
    app.callbacks.get('keydown')({ key: 'Escape' });
    assert.equal(app.document.body.style.overflow, 'auto');
    assert.equal(app.document.documentElement.style.overflow, 'scroll');
});
