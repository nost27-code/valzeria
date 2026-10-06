<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use Illuminate\Support\Collection;

/** 装備カードで共用する、現在装備の比較基準を画面内で一度だけ作る。 */
class EquipmentComparisonService
{
    public function forDisplay(Character $character, Collection $items): array
    {
        $status = app(CharacterStatusService::class);
        $previews = [];
        foreach (['weapon', 'armor'] as $slot) {
            if (!$items->contains(fn (CharacterItem $item): bool => !$item->is_equipped && $item->item?->type === $slot)) {
                continue;
            }
            $equipped = $items->first(fn (CharacterItem $item): bool => (bool) $item->is_equipped && $item->equipped_slot === $slot);
            $previews[$slot] = $equipped
                ? ($slot === 'weapon' ? $status->weaponEffectivePreview($character, $equipped) : $status->armorEffectivePreview($character, $equipped))
                : $status->getFinalStats($character);
        }

        return $previews;
    }
}
