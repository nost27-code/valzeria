<?php

namespace App\Services;

use App\Models\Character;
use App\Services\Nation\Raid\NationRaidRules;

/** 戦闘相手に対して実際に有効な装備効果を、表示用にまとめる。 */
final readonly class BattleEquipmentSummaryService
{
    public function __construct(
        private EquipmentPermissionService $permissionService,
    ) {}

    /**
     * @return list<array{slot:string,rank:string,name:string,icon:?string,trait_label:?string,is_killer_active:bool,is_resist_active:bool,killer_rate:float,resist_rate:float}>
     */
    public function forEnemy(
        Character $character,
        string $enemySpeciesKey,
        string $battleType = 'pve',
    ): array {
        $slotOrder = ['weapon' => 0, 'armor' => 1, 'accessory' => 2];

        return $character->characterItems()
            ->where('is_equipped', true)
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->get()
            ->filter(fn ($characterItem) => $characterItem->item !== null)
            ->sortBy(fn ($characterItem) => [
                $slotOrder[$characterItem->item->type] ?? 99,
                $characterItem->id,
            ])
            ->map(function ($characterItem) use ($enemySpeciesKey, $character, $battleType): array {
                $killerRate = $battleType === NationRaidRules::BATTLE_TYPE
                    ? $this->raidKillerRate($character, $characterItem, $enemySpeciesKey)
                    : $this->permissionService->effectiveKillerDamageRateForSpecies(
                        $character,
                        $characterItem,
                        $enemySpeciesKey,
                    );
                $isKillerActive = $killerRate > 0;
                $resistRate = $this->permissionService->effectiveSpeciesDamageReductionRate($character, $characterItem);
                if ($battleType === NationRaidRules::BATTLE_TYPE) {
                    $resistRate = NationRaidRules::ARMOR_SPECIES_RESISTANCE_ENABLED
                        ? min(NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP, $resistRate)
                        : 0.0;
                }
                $isResistActive = $characterItem->resist_species_key !== null
                    && $resistRate > 0
                    && $enemySpeciesKey !== ''
                    && $characterItem->resist_species_key === $enemySpeciesKey;

                $traitLabel = null;
                if ($isKillerActive) {
                    $traitLabel = '特攻発動 与ダメージ +'.$this->percentageLabel($killerRate).'%';
                } elseif ($isResistActive) {
                    $traitLabel = '耐性発動 被ダメージ -'.$this->percentageLabel($resistRate).'%';
                }

                return [
                    'slot' => $this->slotLabel((string) $characterItem->item->type),
                    'rank' => strtoupper((string) match ($characterItem->item->type) {
                        'weapon' => $characterItem->item->weapon_rank ?? $characterItem->item->rarity,
                        'armor' => $characterItem->item->armor_rank ?? $characterItem->item->rarity,
                        'accessory' => $characterItem->item->accessory_rank ?? $characterItem->item->rarity,
                        default => $characterItem->item->rarity,
                    }),
                    'name' => $characterItem->displayName(false),
                    'icon' => $characterItem->item->iconImagePath(),
                    'trait_label' => $traitLabel,
                    'is_killer_active' => $isKillerActive,
                    'is_resist_active' => $isResistActive,
                    'killer_rate' => $isKillerActive ? $killerRate : 0.0,
                    'resist_rate' => $isResistActive ? $resistRate : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private function raidKillerRate(Character $character, mixed $characterItem, string $enemySpeciesKey): float
    {
        if ($enemySpeciesKey === '') {
            return 0.0;
        }

        $rate = array_sum(array_map(
            static fn (array $effect): float => ($effect['species_key'] ?? null) === $enemySpeciesKey
                ? (float) ($effect['damage_rate'] ?? 0.0)
                : 0.0,
            $this->permissionService->effectiveKillerEffects($character, $characterItem),
        ));

        return NationRaidRules::raidKillerDamageRate($rate);
    }

    private function slotLabel(string $type): string
    {
        return [
            'weapon' => '武器',
            'armor' => '防具',
            'accessory' => '装飾品',
        ][$type] ?? '装備';
    }

    private function percentageLabel(float $rate): string
    {
        $percentage = round($rate * 100, 1);

        return $percentage === floor($percentage)
            ? (string) (int) $percentage
            : number_format($percentage, 1, '.', '');
    }
}
