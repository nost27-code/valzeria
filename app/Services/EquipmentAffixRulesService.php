<?php

namespace App\Services;

use App\Models\EquipmentAffixPrefix;
use App\Models\Item;

class EquipmentAffixRulesService
{
    /**
     * @return array<string, int>
     */
    public function prefixBonuses(Item $item, EquipmentAffixPrefix $prefix, int $level, ?string $quality): array
    {
        $basePower = max(
            (int) ($item->str_bonus ?? 0),
            (int) ($item->def_bonus ?? 0),
            (int) ($item->mag_bonus ?? 0),
            (int) ($item->spr_bonus ?? 0),
            (int) ($item->agi_bonus ?? 0),
            (int) ($item->luk_bonus ?? 0),
            (int) floor(((int) ($item->hp_bonus ?? 0)) / 5),
            1,
        );
        $value = max(1, (int) ceil(
            $basePower
            * $this->engravingEffectRate($this->clampLevel($item, $level))
            * $this->qualityMultiplier($quality)
        ));

        return match ((string) $prefix->target_stat) {
            'hp' => ['hp' => $value * 3],
            'str' => ['str' => $value],
            'def' => ['def' => $value],
            'mag' => ['mag' => $value],
            'spr' => ['spr' => $value],
            'agi' => ['agi' => $value],
            'luk' => ['luk' => $value],
            'all' => [
                'hp' => $value * 3,
                'str' => $value,
                'def' => $value,
                'mag' => $value,
                'spr' => $value,
                'agi' => $value,
                'luk' => $value,
            ],
            default => [],
        };
    }

    public function weaponKillerDamageRate(Item $item, int $level, ?string $quality): float
    {
        return $this->killerDamageRate($this->clampLevel($item, $level))
            * $this->qualityMultiplier($quality);
    }

    public function engravingEffectRate(int $level): float
    {
        return (float) config('equipment_affix.engraving_effect_rates.' . $this->normalizeLevel($level), 0.0);
    }

    public function killerDamageRate(int $level): float
    {
        return (float) config('equipment_affix.killer_damage_rates.' . $this->normalizeLevel($level), 0.0);
    }

    public function qualityMultiplier(?string $quality): float
    {
        return (float) config('equipment_affix.quality_multipliers.' . ($quality ?: 'normal'), 1.0);
    }

    public function maxLevelForItem(?Item $item): int
    {
        $rank = strtoupper((string) ($item?->weapon_rank ?: $item?->armor_rank ?: ''));

        return (int) config('equipment_affix.maximum_level_by_equipment_rank.' . $rank, 1);
    }

    public function clampLevel(?Item $item, int $level): int
    {
        return min($this->maxLevelForItem($item), $this->normalizeLevel($level));
    }

    public function minimumEquipmentRankForLevel(int $level): string
    {
        return (string) config('equipment_affix.minimum_equipment_rank_by_level.' . $this->normalizeLevel($level), 'SS');
    }

    private function normalizeLevel(int $level): int
    {
        return min((int) config('equipment_affix.maximum_level', 5), max(1, $level));
    }
}
