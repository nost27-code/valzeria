<?php

namespace App\Services;

use App\Models\Character;

class EquipmentAutoUnequipService
{
    public function __construct(
        private EquipmentPermissionService $permissionService,
        private CharacterStatusService $statusService
    ) {
    }

    public function unequipInvalidItems(Character $character): array
    {
        $messages = [];

        $items = $character->characterItems()
            ->where('is_equipped', true)
            ->whereIn('equipped_slot', ['weapon', 'armor'])
            ->with('item')
            ->get();

        foreach ($items as $characterItem) {
            if (!$characterItem->item) {
                continue;
            }

            if (!$characterItem->item->is_active) {
                $messages[] = "現在は使用できない装備「{$characterItem->displayName()}」を装備から外しました。";
            } elseif (!$this->permissionService->canEquip($character, $characterItem->item)) {
                $messages[] = "現在の職業では「{$characterItem->displayName()}」を装備できないため、装備から外しました。";
            } else {
                continue;
            }

            $characterItem->is_equipped = false;
            $characterItem->equipped_slot = null;
            $characterItem->save();
        }

        if ($messages) {
            $finalStats = $this->statusService->getFinalStats($character);
            $newMaxHp = $finalStats['max_hp'] ?? $character->hp_base;
            $newMaxMp = $finalStats['max_mp'] ?? $character->mp_base;

            $changed = false;
            if ($character->current_hp > $newMaxHp) {
                $character->current_hp = $newMaxHp;
                $changed = true;
            }
            if (($character->current_mp ?? 0) > $newMaxMp) {
                $character->current_mp = $newMaxMp;
                $changed = true;
            }
            if ($changed) {
                $character->save();
            }
        }

        return $messages;
    }
}
