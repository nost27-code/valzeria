<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\Item;
use App\Models\MapExplorationBatch;
use App\Models\MapExplorationItemCarry;
use App\Models\MapExplorationResult;
use App\Models\Material;
use App\Models\PlayerValmonEgg;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\MapExplorationDefeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapExplorationDefeatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_beginner_protection_keeps_gold_loot_equipment_and_valmon_egg(): void
    {
        $fixture = $this->createDefeatFixture();

        $result = app(MapExplorationDefeatService::class)->apply($fixture['character'], $fixture['batch']);

        $this->assertTrue((bool) data_get($result, 'beginner_protection.active'));
        $this->assertSame(1000, (int) $fixture['character']->fresh()->money);
        $this->assertSame(10, (int) CharacterMaterial::where('character_id', $fixture['character']->id)->where('material_id', $fixture['material']->id)->value('quantity'));
        $this->assertSame(2, CharacterItem::whereIn('id', [$fixture['first_item']->id, $fixture['second_item']->id])->count());
        $this->assertFalse((bool) $fixture['egg']->fresh()->is_lost);
        $this->assertSame(0, (int) $result['gold_loss']['amount']);
        $this->assertSame(0, (int) $result['material_penalty']['total_lost']);
        $this->assertSame([], $result['valmon_egg_lost']);
    }

    public function test_defeat_loses_the_same_gold_and_half_of_entry_loot_as_normal_exploration(): void
    {
        config()->set('battle.beginner_defeat_protection.battle_limit', 0);
        $fixture = $this->createDefeatFixture();

        $summary = app(MapExplorationDefeatService::class)->currentLootSummary($fixture['character'], $fixture['registration']->id);
        $this->assertSame(2, $summary['material_total']);
        $this->assertSame(2, $summary['item_total']);
        $this->assertSame(1, $summary['risk_material_total']);
        $this->assertSame(1, $summary['risk_item_total']);

        $result = app(MapExplorationDefeatService::class)->apply($fixture['character'], $fixture['batch']);

        $this->assertSame(900, (int) $fixture['character']->fresh()->money);
        $this->assertSame(9, (int) CharacterMaterial::where('character_id', $fixture['character']->id)->where('material_id', $fixture['material']->id)->value('quantity'));
        $this->assertSame(1, CharacterItem::whereIn('id', [$fixture['first_item']->id, $fixture['second_item']->id])->count());
        $this->assertTrue((bool) $fixture['egg']->fresh()->is_lost);
        $this->assertSame(100, (int) $result['gold_loss']['amount']);
        $this->assertSame(2, (int) $result['material_penalty']['total_lost']);
        $this->assertCount(1, $result['valmon_egg_lost']);
    }

    /** @return array<string, mixed> */
    private function createDefeatFixture(): array
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '地図敗北試験地', 'slug' => 'map-defeat-test', 'city_id' => $city->id, 'recommended_level_min' => 1, 'recommended_level_max' => 1]);
        $enemy = Enemy::create(['name' => '地図敗北試験魔物', 'area_id' => $area->id, 'level' => 10, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 10, 'gold_reward' => 1, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $owner = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図発見者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 100]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図挑戦者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 1000]);
        $map = ExplorationMap::create([
            'uuid' => (string) Str::uuid(), 'owner_character_id' => $owner->id, 'source_area_id' => $area->id, 'source_monster_id' => $enemy->id,
            'source_drop_event_uuid' => (string) Str::uuid(), 'seed_encrypted' => 'test', 'seed_hash' => str_repeat('a', 64),
            'map_grade' => 'normal', 'map_level' => 10, 'dungeon_type' => 'ruins', 'reward_profile' => 'experience', 'exploration_limit' => 100,
            'name' => '敗北試験の地図', 'name_parts_json' => [], 'normal_monster_variants_json' => [], 'status' => 'published',
        ]);
        $registration = TownMapRegistration::create(['map_id' => $map->id, 'town_id' => $city->id, 'survey_status' => 'completed', 'exploration_limit' => 100, 'remaining_explorations' => 99, 'published_at' => now(), 'expires_at' => now()->addHour(), 'status' => 'published']);
        $batch = MapExplorationBatch::create([
            'uuid' => (string) Str::uuid(), 'request_uuid' => (string) Str::uuid(), 'registration_id' => $registration->id, 'map_id' => $map->id, 'character_id' => $character->id,
            'requested_count' => 3, 'reserved_count' => 3, 'first_exploration_index' => 1, 'last_exploration_index' => 3, 'status' => 'completed',
        ]);
        $carryItem = Item::where('type', 'consumable')->firstOrFail();
        MapExplorationItemCarry::create(['character_id' => $character->id, 'registration_id' => $registration->id, 'item_id' => $carryItem->id, 'carried_count' => 0]);

        $material = Material::firstOrFail();
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);
        $equipment = Item::whereIn('type', ['weapon', 'armor', 'accessory'])->firstOrFail();
        $firstItem = CharacterItem::create(['character_id' => $character->id, 'item_id' => $equipment->id, 'is_equipped' => false, 'is_locked' => false]);
        $secondItem = CharacterItem::create(['character_id' => $character->id, 'item_id' => $equipment->id, 'is_equipped' => false, 'is_locked' => false]);
        $valmonMaster = ValmonMaster::create(['valmon_key' => 'map-defeat-protection', 'name' => '加護試験ヴァルモン', 'rarity' => 'normal', 'is_active' => true]);
        $egg = PlayerValmonEgg::create(['character_id' => $character->id, 'valmon_master_id' => $valmonMaster->id, 'found_at' => now()]);
        MapExplorationResult::create([
            'batch_id' => $batch->id, 'map_id' => $map->id, 'registration_id' => $registration->id, 'character_id' => $character->id,
            'global_exploration_index' => 1, 'encounter_seed_hash' => str_repeat('b', 64), 'reward_seed_hash' => str_repeat('c', 64),
            'monster_variants_json' => [], 'battle_result' => 'victory',
            'drops_json' => [
                'materials' => [['material_id' => $material->id, 'quantity' => 2]],
                'equipment' => [['character_item_id' => $firstItem->id], ['character_item_id' => $secondItem->id]],
            ],
        ]);

        return [
            'character' => $character,
            'registration' => $registration,
            'batch' => $batch,
            'material' => $material,
            'first_item' => $firstItem,
            'second_item' => $secondItem,
            'egg' => $egg,
        ];
    }
}
