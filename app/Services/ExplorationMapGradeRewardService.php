<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;

class ExplorationMapGradeRewardService
{
    public function __construct(
        private readonly ExplorationMapSeedService $seeds,
        private readonly ExplorationMapLegacyRewardService $legacyRewards,
        private readonly DropService $drops,
    ) {}

    /** @return array{materials: array<int, array<string, mixed>>, equipment: array<int, array<string, mixed>>} */
    public function tryDrop(Character $character, ExplorationMap $map, Enemy $enemy, string $rewardSeed): array
    {
        $result = ['materials' => [], 'equipment' => []];
        $rate = max(0, min(10000, (int) config("exploration_maps.grade_bonus_drop.rates_basis_points.{$map->map_grade}", 0)));

        if ((int) $map->generation_version < 5
            || $rate === 0
            || $this->seeds->int($rewardSeed, 'map:grade-bonus:chance', 1, 10000) > $rate) {
            return $result;
        }

        $pool = $this->weightedConfigPick(
            $rewardSeed,
            'map:grade-bonus:pool',
            (array) config('exploration_maps.grade_bonus_drop.pool_weights', []),
        );

        if ($pool === 'ancient_fragment') {
            $fragment = $this->legacyRewards->gradeBonusAncientFragmentFor($map);
            if ($fragment) {
                $result['materials'][] = $this->drops->grantMaterialReward($character, $fragment, 'map_grade_bonus', $enemy);

                return $result;
            }

            $pool = 'material';
        }

        if ($pool === 'equipment') {
            $slot = $this->weightedConfigPick(
                $rewardSeed,
                'map:grade-bonus:equipment-slot',
                (array) config('exploration_maps.grade_bonus_drop.equipment_slot_weights', []),
            );
            $equipment = $this->drops->grantMapGradeEquipmentBonus($character, $enemy, $slot);
            if ($equipment) {
                $result['equipment'][] = $equipment;

                return $result;
            }

            $pool = 'material';
        }

        if ($pool === 'material') {
            $material = $this->drops->grantMapGradeMaterialBonus($character, $enemy);
            if ($material) {
                $material['kind'] = 'map_grade_bonus';
                $result['materials'][] = $material;
            }
        }

        return $result;
    }

    /** @param array<string, int|float> $weights */
    private function weightedConfigPick(string $seed, string $context, array $weights): string
    {
        $candidates = collect($weights)
            ->map(fn (int|float $weight, string $value): array => [
                'value' => $value,
                'weight' => max(0, (int) round($weight)),
            ])
            ->values()
            ->all();

        if ($candidates === []) {
            return '';
        }

        return (string) ($this->seeds->weightedPick($seed, $context, $candidates)['value'] ?? '');
    }
}
