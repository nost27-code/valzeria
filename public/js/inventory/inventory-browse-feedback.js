(() => {
    window.inventoryBrowseFeedback = () => ({
        browseReady: false,
        hasMaterialFilters() {
            return !!this.materialQuery.trim() || ['materialPurpose', 'materialCategory', 'materialRarity'].some(key => this[key] !== 'all');
        },
        hasEquipmentFilters() {
            return !!this.equipmentQuery.trim() || ['equipmentStatus', 'equipmentQuality', 'equipmentTrait'].some(key => this[key] !== 'all');
        },
        visibleMaterialCount() {
            if (!this.browseReady || !this.$refs.materialGrid) return 0;
            return Array.from(this.$refs.materialGrid.querySelectorAll('[data-material-card]'))
                .filter(card => Number(Alpine.$data(card).remainingQty) > 0 && this.matchesMaterial(card)).length;
        },
        visibleEquipmentCount(type) {
            if (!this.browseReady) return 0;
            const grid = this.$refs[type + 'Grid'];
            return grid ? Array.from(grid.querySelectorAll('[data-equipment-card]')).filter(card => {
                const state = Alpine.$data(card);
                return this.matchesEquipment(card, state.locked, state.equipped);
            }).length : 0;
        },
        clearMaterialFilters() {
            this.materialQuery = ''; this.materialPurpose = 'all'; this.materialCategory = 'all'; this.materialRarity = 'all';
        },
        clearEquipmentFilters() {
            this.equipmentQuery = ''; this.equipmentStatus = 'all'; this.equipmentQuality = 'all'; this.equipmentTrait = 'all';
        },
    });
})();
