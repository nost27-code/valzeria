<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Services\MapExplorationRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapExplorationRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_rewards_are_at_least_ten_percent_above_the_closest_existing_enemy(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '報酬基準試験地', 'slug' => 'map-reward-basis', 'city_id' => $city->id, 'recommended_level_min' => 1, 'recommended_level_max' => 1]);
        $enemy = Enemy::create([
            'name' => '報酬基準試験魔物', 'area_id' => $area->id, 'level' => 99,
            'max_hp' => 987654, 'str' => 12345, 'def' => 2345, 'agi' => 345, 'mag' => 4567, 'spr' => 678, 'luk' => 90,
            'exp_reward' => 1000, 'gold_reward' => 200, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false,
        ]);
        $map = new ExplorationMap(['reward_modifiers_json' => ['exp_multiplier' => .90, 'gold_multiplier' => .90]]);

        $rewards = app(MapExplorationRewardService::class)->rewardsFor(clone $enemy, $map, 200);

        $this->assertSame(1100, $rewards['experience']);
        $this->assertGreaterThanOrEqual(220, $rewards['gold']);
        $this->assertSame(0, app(MapExplorationRewardService::class)->rewardsFor(clone $enemy, $map, 0)['gold']);
    }

    public function test_map_profile_bonus_is_applied_after_the_existing_dungeon_premium(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '報酬傾向試験地', 'slug' => 'map-reward-profile', 'city_id' => $city->id, 'recommended_level_min' => 1, 'recommended_level_max' => 1]);
        $enemy = Enemy::create([
            'name' => '報酬傾向試験魔物', 'area_id' => $area->id, 'level' => 99,
            'max_hp' => 876543, 'str' => 11234, 'def' => 2234, 'agi' => 334, 'mag' => 4456, 'spr' => 567, 'luk' => 89,
            'exp_reward' => 1000, 'gold_reward' => 200, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false,
        ]);
        $baselineMap = new ExplorationMap(['reward_modifiers_json' => ['exp_multiplier' => .90, 'gold_multiplier' => .90]]);
        $map = new ExplorationMap(['reward_modifiers_json' => ['exp_multiplier' => 1.20, 'gold_multiplier' => 1.25]]);

        $service = app(MapExplorationRewardService::class);
        $baseline = $service->rewardsFor(clone $enemy, $baselineMap, 200);
        $rewards = $service->rewardsFor(clone $enemy, $map, 200);

        $this->assertSame((int) floor($baseline['experience'] * 1.20), $rewards['experience']);
        $this->assertSame((int) floor($baseline['gold'] * 1.25), $rewards['gold']);
    }
}
