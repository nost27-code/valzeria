<?php

namespace Tests\Support;

use App\Models\Area;
use App\Models\Enemy;

trait NamelessRuinRewardReferences
{
    protected function installRuinRewardReference(): Enemy
    {
        $area = Area::query()->create(['city_id' => 1, 'name' => '遺跡報酬基準試験', 'slug' => 'ruin-reward-reference',
            'recommended_level_min' => 1, 'recommended_level_max' => 100]);

        return Enemy::query()->create((array) config('nameless_relics.enemy_base') + [
            'name' => '通常報酬基準試験魔物', 'area_id' => $area->id, 'level' => 100,
            'exp_reward' => 17, 'gold_reward' => 10, 'job_exp_reward' => 2, 'appearance_weight' => 1, 'is_boss' => false,
        ]);
    }
}
