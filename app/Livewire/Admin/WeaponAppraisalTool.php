<?php

namespace App\Livewire\Admin;

use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\EquipmentAffixRulesService;
use App\Services\EquipmentMarketAppraisalService;
use Livewire\Component;
use RuntimeException;

class WeaponAppraisalTool extends Component
{
    public string $rank = 'SS';

    public string $quality = 'normal';

    public int $enhanceLevel = 0;

    public int $engravingLevel = 0;

    public int $slayerLevel = 0;

    public function updatedRank(): void
    {
        $maxLevel = $this->maxTraitLevel();
        $this->engravingLevel = min($this->engravingLevel, $maxLevel);
        $this->slayerLevel = min($this->slayerLevel, $maxLevel);
    }

    public function render(EquipmentMarketAppraisalService $appraisalService, EquipmentAffixRulesService $affixRulesService)
    {
        $appraisal = null;
        $error = null;

        try {
            $appraisal = $appraisalService->appraisal($this->buildPreviewItem());
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return view('livewire.admin.weapon-appraisal-tool', [
            'appraisal' => $appraisal,
            'error' => $error,
            'rankValues' => config('equipment_market.weapon_rank_values'),
            'qualityLabels' => ['normal' => '通常品', 'good' => '良品', 'excellent' => '逸品'],
            'maxTraitLevel' => $affixRulesService->maxLevelForItem(new Item(['weapon_rank' => strtoupper($this->rank)])),
        ])->layout('components.layouts.admin');
    }

    private function buildPreviewItem(): CharacterItem
    {
        $item = new Item([
            'type' => 'weapon',
            'weapon_rank' => strtoupper($this->rank),
            'weapon_category' => 'sword',
        ]);

        $attributes = [
            'affix_quality' => $this->quality,
            'enhance_level' => max(0, min(3, $this->enhanceLevel)),
        ];

        $maxTraitLevel = $this->maxTraitLevel();

        if ($this->engravingLevel > 0) {
            $attributes['affix_prefix_id'] = 1;
            $attributes['affix_prefix_level'] = min($this->engravingLevel, $maxTraitLevel);
        }

        if ($this->slayerLevel > 0) {
            $attributes['affix_suffix_id'] = 1;
            $attributes['affix_suffix_level'] = min($this->slayerLevel, $maxTraitLevel);
        }

        $characterItem = new CharacterItem($attributes);
        $characterItem->setRelation('item', $item);

        return $characterItem;
    }

    private function maxTraitLevel(): int
    {
        return (int) config('equipment_affix.maximum_level_by_equipment_rank.' . strtoupper($this->rank), 1);
    }
}
