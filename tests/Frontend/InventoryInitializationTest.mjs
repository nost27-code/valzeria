import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const template = fs.readFileSync(new URL('../../resources/views/inventory/index.blade.php', import.meta.url), 'utf8');
const feedback = fs.readFileSync(new URL('../../resources/views/inventory/browse-feedback.blade.php', import.meta.url), 'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)[1];
const sales = fs.readFileSync(new URL('../../resources/views/inventory/material-sales.blade.php', import.meta.url), 'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)[1];
const pageState = fs.readFileSync(new URL('../../public/js/facilities/facility-page-state.js', import.meta.url), 'utf8');

function warehouse({ missingSales = false, missingPageState = false, deniedStorage = false } = {}) {
    const stores = new Map();
    const factories = new Map();
    const Alpine = {
        store(name, value) { if (value !== undefined) stores.set(name, value); return stores.get(name); },
        data(name, factory) { factories.set(name, factory); },
        $data(card) { return card; },
    };
    const window = { Alpine, scrollY: 0, addEventListener() {}, removeEventListener() {}, requestAnimationFrame(fn) { fn(); } };
    const context = vm.createContext({ window, Alpine, sessionStorage: {
        getItem() { if (deniedStorage) throw new Error('Storage denied'); return null; },
        setItem() { if (deniedStorage) throw new Error('Storage denied'); },
    } });
    vm.runInContext(feedback, context);
    if (!missingSales) vm.runInContext(sales, context);
    if (!missingPageState) vm.runInContext(pageState, context);

    const register = template.match(/<script>\s*(\(\(\) => \{[\s\S]*?const registerInventoryAlpine[\s\S]*?)<\/script>/)[1]
        .replace(/@js\(\(string\) \\Illuminate\\Support\\Str::uuid\(\)\)/g, "'00000000-0000-4000-8000-000000000001'");
    vm.runInContext(register, context);

    const totals = { material_storage_total: 100, material_storage_types: 1, total: 1446 };
    const expression = template.match(/x-data="(\{[\s\S]*?\})"\s+@material-discarded/)[1]
        .replace(/\{\{\s*\(int\)\s*\(\$storageSummary\['([^']+)'\]\s*\?\?\s*0\)\s*\}\}/g, (_, key) => totals[key])
        .replace(/\{\{\s*\(int\)\s*\(\$character->money\s*\?\?\s*0\)\s*\}\}/g, '1234')
        .replace(/@js\(\$character->id\)/g, '42')
        .replace(/@js\(array_column\(\$material(?:Purpose|Category|Rarity)Filters, 'key'\)\)/g, '[]');
    const component = vm.runInContext(`(${expression})`, context);
    Object.assign(component, { $refs: {}, $el: { querySelectorAll: () => [] }, $nextTick: fn => fn(), $watch() {} });
    component.init();
    return { component, stores, factories, window, context, register };
}

test('warehouse core and empty-state methods initialize with only the inline scripts', () => {
    const { component, stores, factories } = warehouse({ missingSales: true, missingPageState: true });
    assert.equal(component.assetTotal, 1446);
    assert.equal(component.handGold, 1234);
    assert.equal(component.materialStorageTotal, 100);
    assert.equal(component.browseReady, true);
    assert.equal(component.hasMaterialFilters(), false);
    assert.equal(component.hasEquipmentFilters(), false);
    assert.equal(component.visibleMaterialCount(), 0);
    assert.equal(component.visibleEquipmentCount('weapon'), 0);
    for (const tab of ['equipment', 'key', 'material']) {
        component.storageTab = tab;
        assert.equal(component.storageTab, tab);
    }
    component.expandConfirm = { key: 'material_storage_expand' };
    assert.equal(component.expandConfirm.key, 'material_storage_expand');
    component.supportConfirm = { key: 'stamina_potion' };
    assert.equal(component.supportConfirm.key, 'stamina_potion');
    assert.equal(stores.has('equipSales'), true);
    assert.equal(typeof factories.get('equipmentWarehouseCard'), 'function');
    assert.doesNotThrow(() => component.destroy());
});

test('missing material sale script blocks its actions and reports the reason without selecting or sending', () => {
    const { stores, window } = warehouse({ missingSales: true });
    const state = stores.get('matSales');
    assert.equal(state.busy, true);
    assert.equal(state.submitting, false);
    assert.equal(state.loadFailed, true);
    assert.match(state.message, /売却・破棄を読み込めませんでした/);
    state.set(1, 20, 100);
    state.remove(1);
    state.clear();
    assert.equal(state.count, 0);
    assert.equal(state.total, 0);
    assert.deepEqual(Object.keys(state.items), []);
    assert.equal(window.inventoryAlpineRegistered, true);
});

test('normal scripts and denied browser storage keep the warehouse usable', () => {
    for (const deniedStorage of [false, true]) {
        const { component, stores } = warehouse({ deniedStorage });
        assert.equal(component.browseReady, true);
        assert.equal(stores.get('matSales').busy, false);
        stores.get('matSales').set(1, 2, 100);
        assert.equal(stores.get('matSales').count, 1);
        assert.equal(stores.get('matSales').total, 200);
        assert.doesNotThrow(() => component.destroy());
    }
});

test('a failed store is recovered after the material functions become available', () => {
    const { stores, context, register } = warehouse({ missingSales: true });
    const failed = stores.get('matSales');
    const equipment = stores.get('equipSales');
    assert.equal(failed.loadFailed, true);
    vm.runInContext(sales, context);
    vm.runInContext(register, context);
    const recovered = stores.get('matSales');
    assert.notEqual(recovered, failed);
    assert.equal(recovered.busy, false);
    assert.equal(recovered.message, '');
    recovered.set(1, 2, 100);
    assert.equal(recovered.total, 200);
    assert.equal(stores.get('equipSales'), equipment);
});

test('reinitialization never replaces a valid store or loses an unresolved sale', () => {
    const { stores, context, register } = warehouse();
    const current = stores.get('matSales');
    current.set(1, 2, 100);
    current.pending = { request_uuid: current.requestUuid, sales: [{ character_material_id: 1, quantity: 2 }] };
    current.uncertain = true;
    const pending = current.pending;
    vm.runInContext(register, context);
    assert.equal(stores.get('matSales'), current);
    assert.equal(current.pending, pending);
    assert.equal(current.uncertain, true);
    assert.equal(current.count, 1);
});
