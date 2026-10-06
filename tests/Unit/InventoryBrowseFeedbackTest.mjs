import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../public/js/inventory/inventory-browse-feedback.js', import.meta.url), 'utf8');
function fixture() {
    const window = {}; const materials = [{ name: '鉄片', remainingQty: 1 }, { name: '魔物の欠片', remainingQty: 2 }];
    const equipment = { weapon: [{ name: '確認の剣', locked: false, equipped: false }], armor: [] };
    vm.runInNewContext(source, { window, Alpine: { $data: card => card } });
    const component = { ...window.inventoryBrowseFeedback(), browseReady: true,
        storageTab: 'material', activeEquipmentTab: 'weapon', materialQuery: '', materialPurpose: 'all', materialCategory: 'all', materialRarity: 'all', materialSort: 'quantity_desc',
        equipmentQuery: '', equipmentStatus: 'all', equipmentQuality: 'all', equipmentTrait: 'all', equipmentSort: 'rank_desc', saleSelections: { 1: true },
        $refs: { materialGrid: { querySelectorAll: () => materials }, weaponGrid: { querySelectorAll: () => equipment.weapon }, armorGrid: { querySelectorAll: () => equipment.armor } },
        matchesMaterial(card) { return !this.materialQuery || card.name.includes(this.materialQuery); },
        matchesEquipment(card, locked, equipped) { return (!this.equipmentQuery || card.name.includes(this.equipmentQuery)) && (this.equipmentStatus === 'all' || this.equipmentStatus === 'locked' && locked || this.equipmentStatus === 'ready' && !locked && !equipped); },
    };
    return { component, materials, equipment };
}
test('selling the last matching material reports zero and reset reveals remaining inventory', () => {
    const { component: c, materials } = fixture(); c.materialQuery = '鉄片';
    assert.equal(c.visibleMaterialCount(), 1);
    materials[0].remainingQty = 0;
    assert.equal(c.visibleMaterialCount(), 0); assert.equal(c.hasMaterialFilters(), true);
    c.clearMaterialFilters(); assert.equal(c.hasMaterialFilters(), false); assert.equal(c.visibleMaterialCount(), 1);
    assert.equal(c.materialSort, 'quantity_desc'); assert.deepEqual(c.saleSelections, { 1: true });
});
test('retained filters with no cards can be reset without changing tabs or sort', () => {
    const { component: c } = fixture(); c.storageTab = 'equipment'; c.activeEquipmentTab = 'armor';
    c.equipmentQuery = '最後に売った銘'; c.equipmentStatus = 'locked'; c.equipmentQuality = 'excellent'; c.equipmentTrait = 'prefix';
    assert.equal(c.visibleEquipmentCount('armor'), 0); assert.equal(c.hasEquipmentFilters(), true);
    c.clearEquipmentFilters(); assert.equal(c.hasEquipmentFilters(), false);
    assert.equal(c.activeEquipmentTab, 'armor'); assert.equal(c.equipmentSort, 'rank_desc'); assert.equal(c.storageTab, 'equipment');
});
test('lock changes use current card state without relying on delayed data attribute updates', () => {
    const { component: c, equipment } = fixture(); c.equipmentStatus = 'locked';
    assert.equal(c.visibleEquipmentCount('weapon'), 0);
    equipment.weapon[0].locked = true;
    assert.equal(c.visibleEquipmentCount('weapon'), 1);
    c.equipmentStatus = 'ready'; assert.equal(c.visibleEquipmentCount('weapon'), 0);
    equipment.weapon[0].locked = false; equipment.weapon[0].equipped = true;
    assert.equal(c.visibleEquipmentCount('weapon'), 0);
});
test('all material filters are cleared, and an empty unfiltered inventory remains distinguishable', () => {
    const { component: c, materials } = fixture(); c.materialPurpose = 'craft'; c.materialCategory = 'ore'; c.materialRarity = 'rare';
    assert.equal(c.hasMaterialFilters(), true); c.clearMaterialFilters(); assert.equal(c.hasMaterialFilters(), false);
    materials.forEach(card => card.remainingQty = 0); assert.equal(c.visibleMaterialCount(), 0);
    c.browseReady = false; assert.equal(c.visibleEquipmentCount('weapon'), 0);
});
