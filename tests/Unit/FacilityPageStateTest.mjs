import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/js/facilities/facility-page-state.js', import.meta.url), 'utf8');
function browser(storage = new Map()) {
    const events = new Map();
    const positions = [];
    const window = { scrollY: 780, addEventListener: (key, fn) => events.set(key, fn),
        removeEventListener: key => events.delete(key), requestAnimationFrame: fn => fn(), scrollTo: (x, y) => positions.push(y) };
    const context = { window, sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) } };
    vm.runInNewContext(source, context);
    const component = () => ({ query: '', tab: 'material', selection: {}, money: 10,
        $watch() {}, $nextTick: fn => fn() });
    const rules = { query: v => typeof v === 'string' ? v.slice(0, 200) : '', tab: ['material', 'equipment'] };
    return { attach: window.attachFacilityPageState, craftRule: window.facilityCraftQuantities, component, rules, events, positions, storage };
}

test('reload restores browsing and position while assets and sale selections remain fresh', () => {
    const a = browser();
    const first = a.component();
    a.attach(first, 'inventory', 1, a.rules);
    first.query = '剣'; first.tab = 'equipment'; first.selection = { 5: true }; first.money = 999;
    a.events.get('pagehide')();
    const b = browser(a.storage); const next = b.component();
    b.attach(next, 'inventory', 1, b.rules);
    assert.equal(next.query, '剣'); assert.equal(next.tab, 'equipment');
    assert.deepEqual(next.selection, {}); assert.equal(next.money, 10); assert.deepEqual(b.positions, [780]);
    const other = b.component(); b.attach(other, 'inventory', 2, b.rules);
    assert.equal(other.query, ''); assert.equal(other.tab, 'material');
});
test('corrupt or outdated preferences fall back safely and disabled storage does not break display', () => {
    const a = browser(new Map([['valzeria:inventory:v1:1', '{broken']]));
    assert.doesNotThrow(() => a.attach(a.component(), 'inventory', 1, a.rules));
    a.storage.set('valzeria:inventory:v1:1', JSON.stringify({ values: { tab: 'old', query: 8 }, scrollY: -1 }));
    const c = a.component(); a.attach(c, 'inventory', 1, a.rules);
    assert.equal(c.tab, 'material'); assert.equal(c.query, '');
    assert.deepEqual(a.positions, []);
    const denied = browser({ get() { throw new Error('blocked'); }, set() { throw new Error('blocked'); } });
    assert.doesNotThrow(() => denied.attach(denied.component(), 'inventory', 1, denied.rules));
    assert.doesNotThrow(() => denied.events.get('pagehide')());
});

test('craft reload retains recipe quantities but clamps to current shared stock and does not restore payment consent', () => {
    const a = browser();
    const first = { ...a.component(), quantities: { beast: 6, insect: 4 }, selected: null, useBank: false };
    a.attach(first, 'apothecary', 1, { quantities: a.craftRule({ beast: 10, insect: 8 }) });
    first.quantities = { beast: 6, insect: 4 }; first.useBank = true;
    a.events.get('pagehide')();
    const next = { ...a.component(), quantities: {}, selected: null, useBank: false };
    a.attach(next, 'apothecary', 1, { quantities: a.craftRule({ beast: 3, insect: 0, newRecipe: 8 }) });
    assert.equal(next.quantities.beast, 3); assert.equal(next.quantities.insect, 1); assert.equal(next.quantities.newRecipe, 1);
    assert.equal(next.useBank, false); assert.equal(next.selected, null);
    const rule = a.craftRule({ beast: 10 });
    assert.equal(rule({ beast: 'oops' }).beast, 1);
    assert.equal(rule({ beast: -3 }).beast, 1);
    assert.equal(rule({ beast: 1000 }).beast, 10);
    assert.equal(a.craftRule({ beast: 1000 })({ beast: 200 }).beast, 99);
});

test('craft failure focuses the reason instead of restoring a lower scroll position while preserving quantities', () => {
    const saved = new Map([['valzeria:apothecary:v1:1', JSON.stringify({ values: { quantities: { beast: 7 } }, scrollY: 2400 })]]);
    const a = browser(saved); const calls = [];
    const errorTarget = { isConnected: true, scrollIntoView: options => calls.push(['scroll', options.block]), focus: options => calls.push(['focus', options.preventScroll]) };
    const component = { ...a.component(), quantities: {}, useBank: false };
    a.attach(component, 'apothecary', 1, { quantities: a.craftRule({ beast: 3 }) }, () => calls.push(['restore']), { errorTarget });
    assert.equal(component.quantities.beast, 3); assert.equal(component.useBank, false);
    assert.deepEqual(a.positions, []);
    assert.deepEqual(calls, [['restore'], ['scroll', 'center'], ['focus', true]]);
});
test('a fresh failure is visible without saved browsing, while a detached error does not suppress normal restoration', () => {
    const a = browser(); let focused = false;
    a.attach(a.component(), 'apothecary', 1, a.rules, () => {}, { errorTarget: { isConnected: true, scrollIntoView() {}, focus() { focused = true; } } });
    assert.equal(focused, true);
    const b = browser(new Map([['valzeria:apothecary:v1:1', JSON.stringify({ values: {}, scrollY: 900 })]]));
    b.attach(b.component(), 'apothecary', 1, b.rules, () => {}, { errorTarget: { isConnected: false } });
    assert.deepEqual(b.positions, [900]);
});
