<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\CharacterStatusService;
use App\Services\EquipmentComparisonService;
use App\Services\GoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class InventoryEquipmentLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_preloads_names_and_relic_flags_without_per_item_queries(): void
    {
        $user = User::factory()->create();
        $character = Character::create(['user_id' => $user->id, 'name' => '倉庫表示確認者', 'money' => 1000]);
        $master = ValmonMaster::create(['valmon_key' => 'loading-test', 'name' => '確認モン', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);
        $item = Item::create(['name' => '表示確認の剣', 'type' => 'weapon', 'weapon_rank' => 'C', 'sell_price' => 100]);
        for ($index = 0; $index < 30; $index++) {
            CharacterItem::create(['character_id' => $character->id, 'item_id' => $item->id]);
        }
        // 真のBlade出力を使ったブラウザQAにも同じ隔離fixtureを使う。
        foreach (['鉄片', '魔物の欠片'] as $index => $name) {
            $material = Material::create(['material_code' => 'LOADING_'.$index, 'name' => $name, 'category' => '素材', 'rarity' => 'COMMON', 'npc_sale_price' => 10 + $index * 10]);
            CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);
        }
        DB::enableQueryLog();
        $response = $this->actingAs($user)->withSession(['current_character_id' => $character->id])
            ->get(route('inventory.index'))->assertOk();
        $groups = $response->viewData('equipmentGroups');
        $this->assertCount(30, $groups['weapon']);
        foreach ($groups['weapon'] as $equipment) {
            $this->assertTrue($equipment->relationLoaded('affixPrefix'));
            $this->assertTrue($equipment->relationLoaded('affixSuffix'));
            $this->assertTrue(array_key_exists('relics_exists', $equipment->getAttributes()));
            $this->assertTrue((bool) $equipment->can_sell);
        }
        $standaloneRelicChecks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select exists(select * from "player_relics"'));
        $this->assertCount(0, $standaloneRelicChecks);
        DB::disableQueryLog();
        if ($qaPath = getenv('WAREHOUSE_QA_HTML')) {
            file_put_contents($qaPath, $response->getContent());
        }
    }

    public function test_display_flags_do_not_bypass_live_relic_checks_when_selling(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '表示保護確認者']);
        $item = Item::create(['name' => '確認の剣', 'type' => 'weapon', 'sell_price' => 100]);
        $equipment = CharacterItem::create(['character_id' => $character->id, 'item_id' => $item->id]);
        $equipment->setAttribute('relics_exists', false);
        $service = app(GoldService::class);
        $this->assertTrue($service->canSellEquipmentForDisplay($equipment));
        DB::enableQueryLog();
        $this->assertTrue($service->canSellEquipment($equipment));
        $checks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select exists(select * from "player_relics"'));
        $this->assertCount(1, $checks);
        DB::disableQueryLog();
    }

    public function test_equipped_comparison_preview_is_built_once_per_slot(): void
    {
        $character = new Character();
        $equipped = new CharacterItem(['is_equipped' => true, 'equipped_slot' => 'weapon']);
        $equipped->setRelation('item', new Item(['type' => 'weapon']));
        $items = collect([$equipped]);
        for ($index = 0; $index < 100; $index++) {
            $candidate = new CharacterItem(['is_equipped' => false]);
            $candidate->setRelation('item', new Item(['type' => 'weapon']));
            $items->push($candidate);
        }
        $status = Mockery::mock(CharacterStatusService::class);
        $status->shouldReceive('weaponEffectivePreview')->once()->with($character, $equipped)->andReturn(['str' => 123, 'mag' => 45]);
        $this->app->instance(CharacterStatusService::class, $status);
        $this->assertSame(['weapon' => ['str' => 123, 'mag' => 45]], app(EquipmentComparisonService::class)->forDisplay($character, $items));
    }
}
