<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\MapExplorationItemService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapExplorationItemServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_entry_carries_up_to_ten_recovery_items_and_consumes_only_on_use(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '試験地', 'slug' => 'map-item-test', 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => '試験魔物', 'area_id' => $area->id, 'level' => 50, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図探索者', 'hp_base' => 100, 'current_hp' => 40, 'money' => 10000]);
        $herb = Item::where('type', 'consumable')->where('name', '薬草')->firstOrFail();
        Item::where('type', 'consumable')->where('name', '回復薬')->firstOrFail();
        Item::where('type', 'consumable')->where('name', '魔力水')->firstOrFail();
        foreach (range(1, 12) as $unused) {
            CharacterItem::create(['character_id' => $character->id, 'item_id' => $herb->id, 'is_equipped' => false]);
        }

        $map = app(ExplorationMapGenerator::class)->generate($character, $area, $enemy, '00000000-0000-4000-8000-000000000002');
        $registration = app(MapSurveyService::class)->start($character, $map, $city);
        $service = app(MapExplorationItemService::class);

        $service->begin($character, $registration);
        $herbCarry = collect($service->carriedItems($character, $registration->id))->firstWhere('name', '薬草');

        $this->assertSame(10, $herbCarry['carried_count']);
        $this->assertSame(10, $herbCarry['available_count']);
        $this->assertSame(12, CharacterItem::where('character_id', $character->id)->where('item_id', $herb->id)->count());

        $maxHp = (int) app(\App\Services\CharacterStatusService::class)->getFinalStats($character)['max_hp'];
        $result = $service->use($character, $herb, $registration->id);

        $this->assertTrue($result['success']);
        $this->assertSame(11, CharacterItem::where('character_id', $character->id)->where('item_id', $herb->id)->count());
        $this->assertSame(min($maxHp, 40 + (int) ceil($maxHp * 0.3)), (int) $character->fresh()->current_hp);
        $this->assertSame(9, collect($service->carriedItems($character, $registration->id))->firstWhere('name', '薬草')['available_count']);

        $service->end($character);
        $this->assertSame(0, collect($service->carriedItems($character, $registration->id))->sum('carried_count'));
    }
}
