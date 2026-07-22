<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\User;
use App\Services\ExplorationMapDisplayService;
use App\Services\ExplorationMapLegacyRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapLegacyRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_legacy_plain_fallback_maps_at_level_142_or_higher_show_a_fixed_ancient_fragment(): void
    {
        [, $highEnemy, $lowEnemy] = $this->createEnemies();
        $service = app(ExplorationMapLegacyRewardService::class);
        $display = app(ExplorationMapDisplayService::class);

        $highMap = $this->legacyPlainMap($highEnemy, 142);
        $fragment = $service->ancientFragmentFor($highMap);

        $this->assertNotNull($fragment);
        $this->assertSame('古代片：' . $fragment->displayName(), $display->details($highMap)['reward']);
        $this->assertSame($fragment->id, $service->ancientFragmentFor($highMap)?->id);

        $lowMap = $this->legacyPlainMap($lowEnemy, 141);
        $this->assertNull($service->ancientFragmentFor($lowMap));
        $this->assertNull($display->details($lowMap)['reward']);
    }

    public function test_current_reward_profiles_are_not_replaced_with_ancient_fragments(): void
    {
        [, $highEnemy] = $this->createEnemies();
        $map = $this->legacyPlainMap($highEnemy, 142, 'training', [
            'job_exp_multiplier' => 2.0,
            'job_exp_cap' => 6,
        ]);

        $this->assertNull(app(ExplorationMapLegacyRewardService::class)->ancientFragmentFor($map));
        $this->assertSame('修練の導き', app(ExplorationMapDisplayService::class)->details($map)['reward']);
    }

    public function test_legacy_maps_with_an_existing_reward_modifier_are_not_treated_as_plain_rewards(): void
    {
        [, $highEnemy] = $this->createEnemies();
        $map = $this->legacyPlainMap($highEnemy, 142, 'legacy_material', [
            'material_drop_bonus_points' => 5,
        ]);

        $this->assertNull(app(ExplorationMapLegacyRewardService::class)->ancientFragmentFor($map));
    }

    public function test_legacy_ancient_fragment_is_granted_as_an_additional_victory_drop(): void
    {
        [$area, $enemy] = $this->createEnemies();
        $map = $this->legacyPlainMap($enemy, 142);
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '古代片の探索者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $service = app(ExplorationMapLegacyRewardService::class);
        $fragment = $service->ancientFragmentFor($map);
        $this->assertNotNull($fragment);

        $originalRate = config('exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points');
        config()->set('exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points', 10000);

        try {
            $drop = $service->tryDrop($character, $map, $enemy->setRelation('area', $area), str_repeat('b', 64));
        } finally {
            config()->set('exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points', $originalRate);
        }

        $this->assertSame($fragment->id, $drop['material_id']);
        $this->assertDatabaseHas('character_materials', [
            'character_id' => $character->id,
            'material_id' => $fragment->id,
            'quantity' => 1,
        ]);
        $this->assertSame(1, CharacterMaterial::where('character_id', $character->id)->where('material_id', $fragment->id)->value('quantity'));
    }

    /** @return array{0: Area, 1: Enemy, 2: Enemy} */
    private function createEnemies(): array
    {
        $city = City::findOrFail(1);
        $area = Area::create([
            'name' => '古代片試験地',
            'slug' => 'legacy-ancient-map-test',
            'city_id' => $city->id,
            'recommended_level_min' => 140,
            'recommended_level_max' => 145,
        ]);

        $attributes = ['area_id' => $area->id, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false];

        return [
            $area,
            Enemy::create($attributes + ['name' => '高位試験魔物', 'level' => 142]),
            Enemy::create($attributes + ['name' => '低位試験魔物', 'level' => 141]),
        ];
    }

    private function legacyPlainMap(Enemy $enemy, int $level, string $profile = 'legacy_normal', array $modifiers = []): ExplorationMap
    {
        return new ExplorationMap([
            'seed_hash' => str_repeat('a', 64),
            'map_grade' => 'normal',
            'map_level' => $level,
            'reward_profile' => $profile,
            'reward_modifiers_json' => $modifiers,
            'normal_monster_variants_json' => [[
                'base_monster_id' => $enemy->id,
                'display_name' => $enemy->name,
                'enemy_level' => $level,
                'stat_modifiers' => [],
            ]],
        ]);
    }
}
