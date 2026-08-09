<?php

namespace Tests\Support;

use App\Models\Area;
use App\Models\Enemy;

trait CreatesExplorationMapEnemyFixtures
{
    /** @return array{normal: Enemy, ancient: Enemy} */
    protected function createExplorationMapEnemyFixtures(
        Area $area,
        string $normalName,
        int $normalLevel = 45,
        array $normalOverrides = [],
    ): array {
        $attributes = [
            'area_id' => $area->id,
            'max_hp' => 100,
            'str' => 20,
            'def' => 10,
            'agi' => 10,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
            'exp_reward' => 20,
            'gold_reward' => 10,
            'job_exp_reward' => 1,
            'appearance_weight' => 1,
            'is_boss' => false,
        ];

        return [
            'normal' => Enemy::create(array_replace($attributes, $normalOverrides, [
                'name' => $normalName,
                'level' => $normalLevel,
            ])),
            'ancient' => Enemy::create($attributes + [
                'name' => $normalName . '（古代片帯）',
                'level' => (int) config('exploration_maps.reward_profiles.ancient_fragment.minimum_enemy_level', 142),
            ]),
        ];
    }
}
