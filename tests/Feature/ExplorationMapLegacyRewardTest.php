<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MapExplorationBatch;
use App\Models\MapExplorationResult;
use App\Models\Material;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationMapDisplayService;
use App\Services\ExplorationMapLegacyRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_existing_hero_map_keeps_its_reward_label_after_grade_rewards_are_strengthened(): void
    {
        [, $highEnemy] = $this->createEnemies();
        $map = $this->legacyPlainMap($highEnemy, 142, 'training', [
            'job_exp_multiplier' => 2.0,
            'job_exp_cap' => 6,
        ]);
        $map->map_grade = 'hero';

        $this->assertSame('修練の導き', app(ExplorationMapDisplayService::class)->details($map)['reward']);
    }

    public function test_current_ancient_fragment_profile_uses_its_saved_fragment(): void
    {
        [, $highEnemy] = $this->createEnemies();
        $service = app(ExplorationMapLegacyRewardService::class);
        $fragment = $service->ancientFragmentForSeedHash(str_repeat('c', 64));
        $this->assertNotNull($fragment);

        $map = $this->legacyPlainMap($highEnemy, 142, 'ancient_fragment');
        $map->generation_payload_json = ['ancient_fragment_material_code' => $fragment->material_code];

        $this->assertSame($fragment->id, $service->ancientFragmentFor($map)?->id);
        $this->assertSame('古代片：' . $fragment->displayName(), app(ExplorationMapDisplayService::class)->details($map)['reward']);
        $this->assertArrayNotHasKey('reward_note', app(ExplorationMapDisplayService::class)->details($map));
    }

    public function test_new_ancient_fragment_profile_can_grant_saved_accessory_fragment(): void
    {
        [$area, $enemy] = $this->createEnemies();
        $fragment = Material::query()->where('material_code', 'ACC0004')->firstOrFail();
        $map = $this->legacyPlainMap($enemy, 142, 'ancient_fragment');
        $map->generation_payload_json = ['ancient_fragment_material_code' => $fragment->material_code];
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '装飾片の探索者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $service = app(ExplorationMapLegacyRewardService::class);

        $this->assertSame($fragment->id, $service->ancientFragmentFor($map)?->id);
        $this->assertSame('古代片：' . $fragment->displayName(), app(ExplorationMapDisplayService::class)->details($map)['reward']);

        config()->set('exploration_maps.reward_profiles.ancient_fragment.drop_rate_basis_points', 10000);
        $drop = $service->tryDrop($character, $map, $enemy->setRelation('area', $area), str_repeat('d', 64));

        $this->assertSame($fragment->id, $drop['material_id']);
        $this->assertDatabaseHas('character_materials', [
            'character_id' => $character->id,
            'material_id' => $fragment->id,
            'quantity' => 1,
        ]);
    }

    public function test_ancient_fragment_profile_rate_does_not_change_legacy_plain_maps(): void
    {
        [$area, $enemy] = $this->createEnemies();
        $profileMap = $this->legacyPlainMap($enemy, 142, 'ancient_fragment');
        $plainMap = $this->legacyPlainMap($enemy, 142);
        $fragment = app(ExplorationMapLegacyRewardService::class)->ancientFragmentForSeedHash(str_repeat('c', 64));
        $profileMap->generation_payload_json = ['ancient_fragment_material_code' => $fragment->material_code];
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '地図確率確認者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);

        config()->set('exploration_maps.reward_profiles.ancient_fragment.drop_rate_basis_points', 10000);
        config()->set('exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points', 0);

        $service = app(ExplorationMapLegacyRewardService::class);
        $this->assertNotNull($service->tryDrop($character, $profileMap, $enemy->setRelation('area', $area), str_repeat('d', 64)));
        $this->assertNull($service->tryDrop($character, $plainMap, $enemy, str_repeat('e', 64)));
    }

    public function test_same_character_receives_fixed_fragment_after_wins_without_it_and_streak_resets(): void
    {
        $this->assertSame(100, config('exploration_maps.reward_profiles.ancient_fragment.drop_rate_basis_points'));
        $this->assertSame(100, config('exploration_maps.reward_profiles.ancient_fragment.guaranteed_after_wins_without_fragment'));

        [$area, $enemy] = $this->createEnemies();
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図救済確認者']);
        $fragment = Material::where('material_code', 'ACC0004')->firstOrFail();
        $map = $this->legacyPlainMap($enemy, 142, 'ancient_fragment');
        $map->fill([
            'uuid' => (string) Str::uuid(), 'owner_character_id' => $character->id,
            'source_area_id' => $area->id, 'source_monster_id' => $enemy->id,
            'source_drop_event_uuid' => (string) Str::uuid(), 'seed_encrypted' => 'test',
            'dungeon_type' => 'ruins', 'exploration_limit' => 300, 'name' => '救済確認の地図',
            'name_parts_json' => [], 'generation_payload_json' => ['ancient_fragment_material_code' => $fragment->material_code],
        ]);
        $map->save();
        $registration = TownMapRegistration::create([
            'map_id' => $map->id, 'town_id' => City::findOrFail(1)->id,
            'exploration_limit' => 300, 'remaining_explorations' => 295,
        ]);
        $otherCharacter = Character::create(['user_id' => User::factory()->create()->id, 'name' => '別の地図探索者']);
        $otherBatch = MapExplorationBatch::create([
            'uuid' => (string) Str::uuid(), 'request_uuid' => (string) Str::uuid(),
            'registration_id' => $registration->id, 'map_id' => $map->id, 'character_id' => $otherCharacter->id,
            'requested_count' => 2, 'reserved_count' => 2, 'first_exploration_index' => 1, 'last_exploration_index' => 2,
        ]);
        $batch = MapExplorationBatch::create([
            'uuid' => (string) Str::uuid(), 'request_uuid' => (string) Str::uuid(),
            'registration_id' => $registration->id, 'map_id' => $map->id, 'character_id' => $character->id,
            'requested_count' => 3, 'reserved_count' => 3, 'first_exploration_index' => 3, 'last_exploration_index' => 5,
        ]);
        $recordWin = function (int $index, array $materials = []) use ($batch, $map, $registration, $character): void {
            MapExplorationResult::create([
                'batch_id' => $batch->id, 'map_id' => $map->id, 'registration_id' => $registration->id,
                'character_id' => $character->id, 'global_exploration_index' => $index,
                'encounter_seed_hash' => str_repeat('b', 64), 'reward_seed_hash' => str_repeat('c', 64),
                'monster_variants_json' => [], 'battle_result' => 'victory',
                'drops_json' => ['materials' => $materials, 'equipment' => []],
            ]);
        };

        config()->set('exploration_maps.reward_profiles.ancient_fragment.drop_rate_basis_points', 0);
        config()->set('exploration_maps.reward_profiles.ancient_fragment.guaranteed_after_wins_without_fragment', 3);
        $service = app(ExplorationMapLegacyRewardService::class);

        foreach ([1, 2] as $index) {
            MapExplorationResult::create([
                'batch_id' => $otherBatch->id, 'map_id' => $map->id, 'registration_id' => $registration->id,
                'character_id' => $otherCharacter->id, 'global_exploration_index' => $index,
                'encounter_seed_hash' => str_repeat('b', 64), 'reward_seed_hash' => str_repeat('c', 64),
                'monster_variants_json' => [], 'battle_result' => 'victory',
                'drops_json' => ['materials' => [], 'equipment' => []],
            ]);
        }
        $this->assertNull($service->tryDrop($character, $map, $enemy->setRelation('area', $area), str_repeat('a', 64)));

        $recordWin(3);
        $this->assertNull($service->tryDrop($character, $map, $enemy->setRelation('area', $area), str_repeat('d', 64)));
        $recordWin(4);
        $drop = $service->tryDrop($character, $map, $enemy, str_repeat('e', 64));
        $this->assertSame($fragment->id, $drop['material_id']);
        $this->assertSame('map_ancient_fragment', $drop['kind']);
        $this->assertSame(1, (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $fragment->id)->value('quantity'));

        $recordWin(5, [$drop]);
        $this->assertNull($service->tryDrop($character, $map, $enemy, str_repeat('f', 64)));
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
