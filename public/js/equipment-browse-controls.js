(function () {
    window.equipmentBrowseControls = function () {
        return {
            browseQuery: '',
            browseStatus: 'all',
            browseQuality: 'all',
            normalizeEquipmentText(value) {
                return String(value || '').normalize('NFKC').toLocaleLowerCase('ja');
            },
            matchesEquipment(data) {
                const query = this.normalizeEquipmentText(this.browseQuery).trim();
                return (!query || this.normalizeEquipmentText(data.browseSearch).includes(query))
                    && (this.browseQuality === 'all' || data.browseQuality === this.browseQuality)
                    && (this.browseStatus === 'all'
                        || (this.browseStatus === 'equipped' && data.browseEquipped === '1')
                        || (this.browseStatus === 'locked' && data.browseLocked === '1')
                        || (this.browseStatus === 'ready' && data.browseEquipped !== '1' && data.browseLocked !== '1'));
            },
            equipmentQualityOrder(value) {
                return { excellent: 2, good: 1, normal: 0 }[value] || 0;
            },
        };
    };
})();
