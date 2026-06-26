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

    public function shouldApplyAffix(?Item $item): bool
    {
        return $item !== null
            && (string) $item->type === 'weapon'
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
        $bonuses = $this->calculatePrefixBonuses($item, $prefix, $quality);

        $suffix = null;
        $killerSpeciesKey = null;
        $killerDamageRate = 0.0;
        if (in_array($tier, self::PREFIX_SUFFIX_TIERS, true)) {
            $suffix = $this->rollSuffix();
            if ($suffix) {
                $killerSpeciesKey = (string) $suffix->species_key;
                $killerDamageRate = $this->resolveKillerRate($suffix, $quality);
            }
        }

        $characterItem->forceFill([
            'affix_prefix_id' => $prefix->id,
            'affix_suffix_id' => $suffix?->id,
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

    private function rollSuffix(): ?EquipmentAffixSuffix
    {
        return $this->weightedRow(
            EquipmentAffixSuffix::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    private function calculatePrefixBonuses(Item $item, EquipmentAffixPrefix $prefix, string $quality): array
    {
        $basePower = max(
            (int) ($item->str_bonus ?? 0),
            (int) ($item->def_bonus ?? 0),
            (int) ($item->mag_bonus ?? 0),
            (int) ($item->spr_bonus ?? 0),
            (int) ($item->agi_bonus ?? 0),
            (int) ($item->luk_bonus ?? 0),
            (int) floor(((int) ($item->hp_bonus ?? 0)) / 5),
            1
        );
        $multiplier = $this->qualityMultiplier($quality);
        $rate = (float) $prefix->calculation_rate;
        $value = max(1, (int) ceil($basePower * $rate * $multiplier));

        return match ((string) $prefix->target_stat) {
            'hp' => ['hp' => $value],
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

    private function resolveKillerRate(EquipmentAffixSuffix $suffix, string $quality): float
    {
        return match ($quality) {
            'good' => 0.0600,
            'excellent' => 0.0700,
            default => max(0.0, (float) $suffix->base_killer_rate),
        };
    }

    private function qualityMultiplier(string $quality): float
    {
        return match ($quality) {
            'good' => 1.2,
            'excellent' => 1.5,
            default => 1.0,
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
