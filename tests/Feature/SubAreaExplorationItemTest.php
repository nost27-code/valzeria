<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\ExplorationItemCarry;
use App\Models\Item;
use App\Models\User;
use App\Services\ExplorationItemService;
use App\Services\ExplorationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubAreaExplorationItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_area_context_carries_and_uses_items_without_normal_exploration_state(): void
    {
        $area = $this->createArea();
        $character = $this->createCharacterWithHerbs(12);
        $herb = Item::query()->where('type', 'consumable')->where('name', '薬草')->firstOrFail();
        $service = app(ExplorationItemService::class);

        $herbCarry = collect($service->carriedItems($character, $area->id))->firstWhere('name', '薬草');

        $this->assertSame(10, $herbCarry['carried_count']);
        $this->assertSame(10, $herbCarry['available_count']);

        $result = $service->use($character, $herb, $area->id);

        $this->assertTrue($result['success']);
        $this->assertSame(11, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $herb->id)->count());
        $this->assertGreaterThan(40, (int) $character->fresh()->current_hp);
        $this->assertSame(9, collect($service->carriedItems($character, $area->id))->firstWhere('name', '薬草')['available_count']);
        $this->assertDatabaseHas('exploration_item_carries', [
            'character_id' => $character->id,
            'area_id' => $area->id,
            'item_id' => $herb->id,
            'carried_count' => 10,
            'used_count' => 1,
        ]);
    }

    public function test_item_use_endpoint_uses_trusted_sub_area_result_context(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $area = $this->createArea();
        $character = $this->createCharacterWithHerbs(12);
        $herb = Item::query()->where('type', 'consumable')->where('name', '薬草')->firstOrFail();

        $response = $this->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                'lastBattleData' => [
                    'areaId' => $area->id,
                    'isBoss' => false,
                    'result' => ['special_event' => 'sub_area_explore'],
                ],
            ])
            ->postJson(route('exploration.items.use', ['item' => $herb->id]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('items.0.name', '薬草')
            ->assertJsonPath('items.0.available_count', 9);

        $this->assertSame(11, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $herb->id)->count());
        $this->assertSame($area->id, (int) ExplorationItemCarry::query()
            ->where('character_id', $character->id)
            ->where('item_id', $herb->id)
            ->value('area_id'));
    }

    public function test_normal_exploration_still_uses_its_persisted_area_context(): void
    {
        $area = $this->createArea();
        $character = $this->createCharacterWithHerbs(12);
        $herb = Item::query()->where('type', 'consumable')->where('name', '薬草')->firstOrFail();
        app(ExplorationStateService::class)->getOrStart($character, $area->id);
        $service = app(ExplorationItemService::class);

        $this->assertSame(10, collect($service->carriedItems($character))->firstWhere('name', '薬草')['available_count']);
        $this->assertTrue($service->use($character, $herb)['success']);
        $this->assertSame(9, collect($service->carriedItems($character))->firstWhere('name', '薬草')['available_count']);
    }

    public function test_only_sub_area_battle_data_exposes_an_explicit_item_area(): void
    {
        $this->assertSame(7, ExplorationItemService::subAreaSourceAreaId([
            'areaId' => 7,
            'result' => ['special_event' => 'sub_area_explore'],
        ]));
        $this->assertNull(ExplorationItemService::subAreaSourceAreaId([
            'areaId' => 7,
            'result' => ['special_event' => 'treasure'],
        ]));
        $this->assertNull(ExplorationItemService::subAreaSourceAreaId([
            'areaId' => 0,
            'result' => ['special_event' => 'sub_area_explore'],
        ]));
    }

    private function createCharacterWithHerbs(int $count): Character
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '亜域探索者',
            'hp_base' => 100,
            'current_hp' => 40,
            'money' => 0,
        ]);
        $herb = Item::query()->where('type', 'consumable')->where('name', '薬草')->firstOrFail();

        foreach (range(1, $count) as $unused) {
            CharacterItem::query()->create([
                'character_id' => $character->id,
                'item_id' => $herb->id,
                'is_equipped' => false,
            ]);
        }

        return $character;
    }

    private function createArea(): Area
    {
        return Area::query()->create([
            'name' => '亜域回復試験地',
            'slug' => 'sub-area-recovery-test',
            'city_id' => City::query()->findOrFail(1)->id,
            'recommended_level_min' => 1,
            'recommended_level_max' => 10,
        ]);
    }
}
