const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { performance } = require('node:perf_hooks');

// Run the real Blade screen's filtering logic, without database writes.
const view = fs.readFileSync('resources/views/smith/traits.blade.php', 'utf8');
const script = view.split('<script>')[1].split('</script>')[0]
    .replace(/@if\(\$relicProtectionAvailable\)|@endif/g, '')
    .replace(/@js\([^\n]+\)\[level\]/, 'romanLevels[level]');
const context = vm.createContext({ romanLevels: [], Intl });
vm.runInContext(script, context);
const trait = (id, name, level = 1) => ({ id, name, level, label: `${name}${level}` });
const item = (id, name, category, engraving, extra = {}) => ({
    id, item_name: name, display_name: name, item_type: 'weapon', weapon_category: category,
    engraving, slayer: trait(null, null, 0), quality: 'normal', ...extra,
});
const items = [
    item(1, '木の剣', '剣', trait(11, '魔導の')),
    item(2, '魔晶球', '魔導具', trait(12, '剛力の')),
    item(3, '大魔導の杖', '杖', trait(12, '剛力の')),
    item(4, '鉄の剣', '剣', trait(11, '魔導の', 5), { is_locked: true }),
    item(5, 'ローブ', 'ローブ', trait(11, '魔導の', 2), { item_type: 'armor' }),
    item(6, '木の盾', '盾', trait(null, null, 0), { item_type: 'armor' }),
];
const group = { base_options: items, material_options: items.filter(x => x.engraving.id), gold_costs: {} };
const state = context.weaponTraitWorkshop({ engraving: group, slayer: group }, {}, .8, 'engraving', '', '', 0, 0, '');
const ids = () => Array.from(state.pickerMatches(), x => x.id);
const before = JSON.stringify(items);
for (const target of ['base', 'material']) {
    state.openPicker(target);
    state.pickerQuery = '魔導';
    assert.deepEqual(ids(), [1, 2, 3, 4, 5], 'legacy free text still includes equipment names/categories');
    state.pickerEngraving = '11';
    assert.deepEqual(ids(), [1, 4, 5], 'exact ID excludes false positives and includes every level');
    assert.equal(state.pickerHasActiveFilters(), true);
    assert.equal(state.pickerEngravings().length, 2, 'one option per ID, without level suffix');
    assert.equal(state.pickerEngravings().find(x => x.id === 11).name, '魔導の');
    state.pickerQuery = '鉄';
    assert.deepEqual(ids(), [4], 'free text and engraving compose with AND');
    state.pickerQuery = '';
    state.setPickerType('weapon');
    assert.deepEqual(ids(), [1, 4]);
    state.pickerCategory = '杖';
    assert.deepEqual(ids(), []);
    state.pickerCategory = '剣';
    state.pickerStatus = 'ready';
    assert.deepEqual(ids(), [1], 'protected material is still excluded by ready filter');
    state.resetPickerControls();
    assert.equal(state.pickerEngraving, 'all');
    assert.equal(state.pickerHasActiveFilters(), false);
    assert.equal(ids().length, target === 'base' ? 6 : 5);
    state.pickerEngraving = '11';
    state.openPicker(target);
    assert.equal(state.pickerEngraving, 'all', 'reopening resets all controls consistently');
}
state.baseId = 1;
state.openPicker('material');
state.pickerEngraving = '11';
assert.deepEqual(ids(), [4, 5], 'same instance cannot become its own material');
state.setKind('slayer');
assert.equal(state.pickerEngraving, 'all');
assert.equal(state.baseId, 1, 'filter changes preserve the selected base');
assert.equal(JSON.stringify(items), before, 'filtering never mutates equipment');
const watchers = {};
state.$watch = (key, callback) => { watchers[key] = callback; };
state.init();
state.pickerLimit = 120;
watchers.pickerEngraving();
assert.equal(state.pickerLimit, 40, 'engraving changes reset pagination');

// A synthetic stress sample, not a claim about the live inventory limit.
group.material_options = Array.from({ length: 10000 }, (_, i) => ({ ...items[i % items.length], id: i + 100 }));
state.openPicker('material');
state.pickerEngraving = '11';
const started = performance.now();
assert.equal(state.pickerMatches().length, 5000);
assert.equal(state.pickerItems().length, 40);
state.pickerLimit += 40;
assert.equal(state.pickerItems().length, 80);
assert.equal(state.pickerEngravings().length, 2);
console.log(`PASS: exact engraving ID, levels, base/material, combined filters, empty/reset/reopen, protected status, selection, pagination; synthetic 10,000 items ${Math.round(performance.now() - started)}ms`);
