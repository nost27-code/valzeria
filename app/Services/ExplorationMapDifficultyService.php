<?php

namespace App\Services;

use App\Models\Enemy;
use App\Models\ExplorationMap;

class ExplorationMapDifficultyService
{
    public function levelOffsetRange(string $grade): array
    {
        $range = config("exploration_maps.grade_level_offsets.{$grade}", config('exploration_maps.grade_level_offsets.normal'));

        return [
            'min' => max(0, (int) ($range['min'] ?? 0)),
            'max' => max(0, (int) ($range['max'] ?? 0)),
        ];
    }

    public function targetLevel(Enemy $enemy, array $variant, string $grade): int
    {
        $baseLevel = max(1, (int) $enemy->level);
        $range = $this->levelOffsetRange($grade);
        $targetLevel = (int) ($variant['enemy_level'] ?? ($baseLevel + $range['min']));

        return min(255, max($baseLevel, $targetLevel));
    }

    public function applyToEnemy(Enemy $enemy, int $targetLevel): void
    {
        $baseLevel = max(1, (int) $enemy->level);
        $levelDifference = max(0, $targetLevel - $baseLevel);
        $hpMultiplier = 1 + ($levelDifference * (float) config('exploration_maps.level_scaling.hp_per_level'));
        $statMultiplier = 1 + ($levelDifference * (float) config('exploration_maps.level_scaling.combat_stat_per_level'));

        $enemy->level = $targetLevel;
        $enemy->max_hp = max(1, (int) floor((int) $enemy->max_hp * $hpMultiplier));
        foreach (['str', 'def', 'agi', 'mag', 'spr', 'luk'] as $attribute) {
            $enemy->{$attribute} = max(1, (int) floor((int) $enemy->{$attribute} * $statMultiplier));
        }
    }

    public function enemyLevels(ExplorationMap $map): array
    {
        $variants = $map->normal_monster_variants_json ?? [];
        if ($variants === []) {
            return [];
        }

        $enemies = Enemy::whereIn('id', collect($variants)->pluck('base_monster_id')->filter()->all())->get()->keyBy('id');

        return collect($variants)
            ->map(function (array $variant) use ($enemies, $map): ?int {
                $enemy = $enemies->get((int) ($variant['base_monster_id'] ?? 0));

                return $enemy ? $this->targetLevel($enemy, $variant, (string) $map->map_grade) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function threatTier(int $enemyLevel): array
    {
        $level = min(255, max(1, $enemyLevel));
        foreach (config('exploration_maps.threat_tiers', []) as $tier) {
            if ($level >= (int) $tier['min'] && $level <= (int) $tier['max']) {
                return $tier;
            }
        }

        return ['min' => 240, 'max' => 255, 'name' => '神話級', 'max_fee_multiplier' => 9.0];
    }

    public function threatTierForMap(ExplorationMap $map): array
    {
        return $this->threatTier(max($this->enemyLevels($map) ?: [(int) $map->map_level]));
    }
}
