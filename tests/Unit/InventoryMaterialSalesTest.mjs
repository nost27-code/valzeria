import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const script = fs.readFileSync(new URL('../../resources/views/inventory/material-sales.blade.php', import.meta.url), 'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)[1];
function setup(fetch, source = script) {
    const events = [];
    const context = { window: { dispatchEvent: event => events.push(event) }, fetch,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } } };
    vm.createContext(context);
    vm.runInContext(source, context);
    const store = context.window.createMaterialSalesStore('first-uuid');
    context.Alpine = { store: () => store };
    store.set(1, 2, 10);
    store.set(2, 3, 20);
    return { store, events, sell: () => context.window.bulkSellMaterials('csrf', '/inventory/bulk-sell') };
}
const success = { ok: true, status: 200, json: async () => ({ success: true, message: '売却しました。',
    money: 1080, quantity: 5, next_request_uuid: 'next-uuid', sales: [
        { character_material_id: 1, remaining_quantity: 8 }, { character_material_id: 2, remaining_quantity: 0 },
    ] }) };

test('the previous external asset remains usable by cached warehouse HTML', async () => {
    const legacy = fs.readFileSync(new URL('../../public/js/inventory/inventory-material-sales.js', import.meta.url), 'utf8');
    let calls = 0;
    const { store, sell } = setup(async () => { calls++; return success; }, legacy);
    await sell();
    assert.equal(calls, 1);
    assert.equal(store.count, 0);
    assert.equal(store.requestUuid, 'next-uuid');
});

test('one request sells all selected types and updates the page without reloading', async () => {
    const calls = [];
    const { store, events, sell } = setup(async (...args) => { calls.push(args); return success; });
    await sell();
    assert.equal(calls.length, 1);
    assert.equal(JSON.parse(calls[0][1].body).sales.length, 2);
    assert.equal(calls[0][1].headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(store.count, 0);
    assert.equal(store.requestUuid, 'next-uuid');
    assert.equal(events[0].type, 'materials-sold');
    assert.equal(events[0].detail.quantity, 5);
    assert.match(store.message, /1,080G/);
    assert.equal(store.busy, false);
});

test('double click is blocked while the first request is pending', async () => {
    let complete;
    let calls = 0;
    const { store, sell } = setup(() => { calls++; return new Promise(resolve => { complete = resolve; }); });
    const first = sell();
    assert.equal(store.submitting, true);
    store.set(1, 9, 10);
    assert.equal(store.items[1].qty, 2);
    await sell();
    assert.equal(calls, 1);
    complete(success);
    await first;
});

test('definitive failure shows its reason and retains editable selections', async () => {
    const { store, events, sell } = setup(async () => ({ ok: false, status: 422, json: async () => ({ success: false, message: '素材数が不足しています。' }) }));
    await sell();
    assert.equal(store.count, 2);
    assert.equal(store.message, '素材数が不足しています。');
    assert.equal(store.messageType, 'error');
    assert.equal(store.busy, false);
    assert.equal(store.pending, null);
    assert.equal(events.length, 0);
    store.set(1, 1, 10);
    assert.equal(store.items[1].qty, 1);
});

test('lost response keeps the same operation and payload for result confirmation', async () => {
    const payloads = [];
    const { store, sell } = setup(async (_url, options) => {
        payloads.push(options.body);
        if (payloads.length === 1) throw new Error('connection lost after commit');
        return success;
    });
    await sell();
    assert.equal(store.uncertain, true);
    assert.equal(store.busy, true);
    assert.match(store.message, /結果を確認する/);
    store.clear();
    store.set(1, 9, 10);
    store.remove(2);
    assert.equal(store.count, 2);
    assert.equal(store.items[1].qty, 2);
    await sell();
    assert.equal(payloads[0], payloads[1]);
    assert.equal(store.uncertain, false);
});

test('HTML redirects, server errors, and incomplete success are never silently accepted', async () => {
    for (const response of [
        { ok: true, status: 200, redirected: true, json: async () => { throw new Error('HTML'); } },
        { ok: false, status: 500, json: async () => ({ message: 'server failure' }) },
        { ok: true, status: 200, json: async () => ({ success: true }) },
    ]) {
        const { store, events, sell } = setup(async () => response);
        await sell();
        assert.equal(store.count, 2);
        assert.equal(store.uncertain, true);
        assert.equal(events.length, 0);
    }
});

test('discard completion can update selection while sales are blocked', async () => {
    let calls = 0;
    const { store, sell } = setup(async () => { calls++; return success; });
    store.discarding = 1;
    await sell();
    assert.equal(calls, 0);
    store.set(1, 1, 10);
    store.remove(2);
    assert.equal(store.count, 1);
    store.discarding = 0;
    assert.equal(store.busy, false);
});
