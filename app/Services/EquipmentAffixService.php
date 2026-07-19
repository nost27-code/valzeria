<?php

namespace App\Services;

use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use Illuminate\Support\Collection;

class EquipmentAffixService
{
    private const PREFIX_SUFFIX_TIERS = [
        'prefix_suffix_normal',
        'prefix_suffix_good',
        'prefix_suffix_excellent',
    ];

    public function __construct(private readonly EquipmentAffixRulesService $rules)
    {
    }

    public function shouldApplyAffix(?Item $item): bool
    {
        return $item !== null
            && in_array((string) $item->type, ['weapon', 'armor'], true)
            && (bool) ($item->affix_enabled ?? false);
    }

    public function applyRandomAffixToDroppedItem(CharacterItem $characterItem): CharacterItem
    {
        $characterItem->loadMissing('item');
        $item = $characterItem->item;

        if (!$this->shouldApplyAffix($item)) {
            return $characterItem;
        }

        $tier = $this->rollAffixTier();
        if ($tier === 'none') {
            return $characterItem;
        }

        $prefix = $this->rollPrefix();
        if (!$prefix) {
            return $characterItem;
        }

        $quality = match ($tier) {
            'prefix_suffix_good' => 'good',
            'prefix_suffix_excellent' => 'excellent',
            default => 'normal',
        };
        $prefixLevel = $this->rules->clampLevel($item, 1);
        $bonuses = $this->rules->prefixBonuses($item, $prefix, $prefixLevel, $quality, (int) ($characterItem->enhance_level ?? 0));

        $suffix = null;
        $killerSpeciesKey = null;
        $killerDamageRate = 0.0;
        $resistSpeciesKey = null;
        $speciesDamageReductionRate = 0.0;
        $suffixLevel = 0;
        if (in_array($tier, self::PREFIX_SUFFIX_TIERS, true)) {
            $suffix = $this->rollSuffixForItemType((string) $item->type);
            if ($suffix && (string) $item->type === 'weapon') {
                $suffixLevel = $this->rules->clampLevel($item, 1);
                $killerSpeciesKey = (string) $suffix->species_key;
                $killerDamageRate = $this->rules->weaponKillerDamageRate($item, $suffixLevel, $quality);
            }
            if ($suffix && (string) $item->type === 'armor') {
                $suffixLevel = $this->rules->clampLevel($item, 1);
                $resistSpeciesKey = (string) $suffix->species_key;
                $speciesDamageReductionRate = $this->resolveArmorReductionRate($suffix, $quality);
            }
        }

        $characterItem->forceFill([
            'affix_prefix_id' => $prefix->id,
            'affix_prefix_level' => $prefixLevel,
            'affix_suffix_id' => $suffix?->id,
            'affix_suffix_level' => $suffix ? $suffixLevel : 0,
            'affix_quality' => $quality,
            'affix_hp_bonus' => $bonuses['hp'] ?? 0,
            'affix_str_bonus' => $bonuses['str'] ?? 0,
            'affix_def_bonus' => $bonuses['def'] ?? 0,
            'affix_mag_bonus' => $bonuses['mag'] ?? 0,
            'affix_spr_bonus' => $bonuses['spr'] ?? 0,
            'affix_agi_bonus' => $bonuses['agi'] ?? 0,
            'affix_luk_bonus' => $bonuses['luk'] ?? 0,
            'killer_species_key' => $killerSpeciesKey,
            'killer_damage_rate' => $killerDamageRate,
            'resist_species_key' => $resistSpeciesKey,
            'species_damage_reduction_rate' => $speciesDamageReductionRate,
            'affix_generated_at' => now(),
        ])->save();

        return $characterItem->refresh()->loadMissing('item', 'affixPrefix', 'affixSuffix');
    }

    public function rollAffixTier(): string
    {
        $roll = random_int(1, 10000);

        return match (true) {
            $roll <= 7000 => 'none',
            $roll <= 9000 => 'prefix_only',
            $roll <= 9800 => 'prefix_suffix_normal',
            $roll <= 9950 => 'prefix_suffix_good',
            default => 'prefix_suffix_excellent',
        };
    }

    /**
     * 鍛冶成功時に、銘または特攻を持つ武器だけを品質昇格の対象にする。
     *
     * @return 'good'|'excellent'|null 昇格した品質。昇格しなければ null。
     */
    public function upgradeQualityAfterWeaponForge(CharacterItem $characterItem): ?string
    {
        $characterItem->loadMissing(['item', 'affixPrefix', 'affixSuffix']);
        if (($characterItem->item?->type ?? null) !== 'weapon'
            || (!$characterItem->affix_prefix_id && !$characterItem->affix_suffix_id)) {
            return null;
        }

        $currentQuality = (string) ($characterItem->affix_quality ?: 'normal');
        $nextQuality = $this->qualityAfterForgeRoll($currentQuality);
        if ($nextQuality === $currentQuality) {
            return null;
        }

        $characterItem->affix_quality = $nextQuality;
        $this->refreshAffixEffects($characterItem);
        $characterItem->save();

        return $nextQuality;
    }

    /**
     * @return 'normal'|'good'|'excellent'
     */
    public function qualityAfterForgeRoll(string $currentQuality, ?int $roll = null): string
    {
        if ($currentQuality === 'excellent') {
            return 'excellent';
        }

        $roll ??= random_int(1, 10000);
        $excellentRate = max(0, (int) config('equipment_affix.forge_quality_upgrade_rates_bps.excellent', 10));
        $goodRate = max(0, (int) config('equipment_affix.forge_quality_upgrade_rates_bps.good', 100));

        if ($roll <= $excellentRate) {
            return 'excellent';
        }

        if ($roll <= $excellentRate + $goodRate) {
            return 'good';
        }

        return $currentQuality === 'good' ? 'good' : 'normal';
    }

    private function refreshAffixEffects(CharacterItem $characterItem): void
    {
        $item = $characterItem->item;
        if (!$item) {
            return;
        }

        $fill = [];
        if ($characterItem->affix_prefix_id && $characterItem->affixPrefix) {
            $bonuses = $this->rules->prefixBonuses(
                $item,
                $characterItem->affixPrefix,
                $characterItem->effectiveAffixPrefixLevel(),
                $characterItem->affix_quality,
                (int) ($characterItem->enhance_level ?? 0),
            );
            foreach (['hp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'] as $stat) {
                $fill['affix_' . $stat . '_bonus'] = $bonuses[$stat] ?? 0;
            }
        }

        if ($characterItem->affix_suffix_id) {
            $fill['killer_damage_rate'] = $this->rules->weaponKillerDamageRate(
                $item,
                $characterItem->effectiveAffixSuffixLevel(),
                $characterItem->affix_quality,
            );
        }

        $characterItem->forceFill($fill);
    }

    public function getDisplayName(CharacterItem $characterItem): string
    {
        return $characterItem->displayName();
    }

    public function getAffixEffectLines(CharacterItem $characterItem): array
    {
        return $characterItem->affixEffectLines();
    }

    private function rollPrefix(): ?EquipmentAffixPrefix
    {
        return $this->weightedRow(
            EquipmentAffixPrefix::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    private function rollSuffixForItemType(string $itemType): ?EquipmentAffixSuffix
    {
        $effectType = match ($itemType) {
            'weapon' => 'killer_damage',
            'armor' => 'species_resist',
            default => null,
        };

        if ($effectType === null) {
            return null;
        }

        return $this->weightedRow(
            EquipmentAffixSuffix::query()
                ->where('item_type', $itemType)
                ->where('effect_type', $effectType)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    private function resolveArmorReductionRate(EquipmentAffixSuffix $suffix, string $quality): float
    {
        return match ($quality) {
            'good' => 0.0500,
            'excellent' => 0.0600,
            default => max(0.0, (float) ($suffix->base_effect_rate ?: 0.0400)),
        };
    }

    private function weightedRow(Collection $rows)
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $total = $rows->sum(fn ($row) => max(0, (int) ($row->roll_weight ?? 0)));
        if ($total <= 0) {
            return $rows->first();
        }

        $roll = random_int(1, $total);
        $running = 0;
        foreach ($rows as $row) {
            $running += max(0, (int) ($row->roll_weight ?? 0));
            if ($roll <= $running) {
                return $row;
            }
        }

        return $rows->last();
    }
}
