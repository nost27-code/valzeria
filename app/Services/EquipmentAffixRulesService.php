<?php

namespace App\Services;

use App\Models\EquipmentAffixPrefix;
use App\Models\Item;

class EquipmentAffixRulesService
{
    /**
     * @return array<string, int>
     */
    public function prefixBonuses(Item $item, EquipmentAffixPrefix $prefix, int $level, ?string $quality, int $enhanceLevel = 0): array
    {
        $basePower = $this->engravingBasePower($item, $enhanceLevel);
        $multiplier = $prefix->target_stat === 'all'
            ? $this->allStatMultiplier()
            : 1.0;
        $value = max(1, (int) ceil(
            $basePower
            * $this->engravingEffectRate($this->clampLevel($item, $level))
            * $this->qualityMultiplier($quality)
            * $multiplier
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

    public function allStatMultiplier(): float
    {
        return (float) config('equipment_affix.all_stat_multiplier', 0.55);
    }

    public function maxLevelForItem(?Item $item): int
    {
        $rank = $this->equipmentRank($item);

        return (int) config('equipment_affix.maximum_level_by_equipment_rank.' . $rank, 1);
    }

    public function supportsAffixes(?Item $item): bool
    {
        $rank = $this->equipmentRank($item);
        $maximumLevels = (array) config('equipment_affix.maximum_level_by_equipment_rank', []);

        return $rank !== '' && array_key_exists($rank, $maximumLevels);
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

    private function equipmentRank(?Item $item): string
    {
        return strtoupper((string) ($item?->weapon_rank ?: $item?->armor_rank ?: ''));
    }

    private function engravingBasePower(Item $item, int $enhanceLevel): int
    {
        $stats = app(EquipmentEnhancementService::class)->enhancedStatTotalsForItem($item, max(0, $enhanceLevel));

        return max(
            (int) ($stats['str'] ?? 0),
            (int) ($stats['def'] ?? 0),
            (int) ($stats['mag'] ?? 0),
            (int) ($stats['spr'] ?? 0),
            (int) ($stats['agi'] ?? 0),
            (int) ($stats['luk'] ?? 0),
            (int) floor(((int) ($stats['hp'] ?? 0)) / 5),
            1,
        );
    }
}
