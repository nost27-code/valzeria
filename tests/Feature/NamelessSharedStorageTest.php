<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GameSetting;
use App\Models\Item;
use App\Models\Material;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use App\Services\StorageCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NamelessSharedStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'nameless_relics.drop_chance_bps' => 0, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.cleared_boss_encounter_bps' => 0,
            'nameless_relics.enemy_base' => ['max_hp' => 1, 'str' => 1, 'def' => 1, 'mag' => 1, 'spr' => 1, 'agi' => 1, 'luk' => 1]]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    public function test_stored_and_attached_assets_share_their_pools_even_when_off(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->materials($character, 498);
        $this->equipment($character, 299);
        $attached = PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1,
            'nameless_equipment_id' => $body->id, 'slot_number' => 1]);
        PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_def', 'rank' => 1]);
        $foreign = $this->character();
        $this->body($foreign);
        PlayerRelic::create(['character_id' => $foreign->id, 'effect_key' => 'stat_str', 'rank' => 9]);
        $service = app(StorageCapacityService::class);
        $before = $service->summary($character);
        $this->assertSame(500, $before['material_total']);
        $this->assertSame(300, $before['equipment_total']);
        $this->assertSame(2, $before['relic_total']);
        $this->assertSame(1, $before['nameless_equipment_total']);
        $this->assertSame(0, $before['material_free']);
        $this->assertSame(0, $before['equipment_free']);
        $this->assertTrue($service->isFull($character));
        $this->assertStringContainsString('鍛冶屋', $service->fullMessageHtml($character, $before));
        app(NamelessWorkshopService::class)->detach($character, $attached->id, (string) Str::uuid());
        $this->assertSame($before, $service->summary($character));
        config(['nameless_relics.enabled' => false]);
        $this->assertSame($before, $service->summary($character));
        $this->assertStringNotContainsString('nameless-workshop', $service->fullMessageHtml($character, $before));
        $this->expectExceptionMessage('装備所持枠が不足');
        $service->assertCanReceiveEquipment($character);
    }

    public function test_expanded_warehouse_replaces_the_old_fixed_limits(): void
    {
        $character = $this->character();
        $character->update(['material_storage_limit' => 1200, 'equipment_storage_limit' => 700]);
        $stamp = now();
        PlayerNamelessEquipment::insert(array_fill(0, 61, ['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'created_at' => $stamp, 'updated_at' => $stamp]));
        PlayerRelic::insert(array_fill(0, 301, ['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1, 'created_at' => $stamp, 'updated_at' => $stamp]));
        $this->materials($character, 500);
        $this->equipment($character, 1);
        $summary = app(StorageCapacityService::class)->summary($character);
        $this->assertSame(801, $summary['material_total']);
        $this->assertSame(62, $summary['equipment_total']);
        $this->assertSame(399, $summary['material_free']);
        $this->assertSame(638, $summary['equipment_free']);
        $this->assertNull(app(NamelessWorkshopService::class)->inventoryBlockReason($character));
        $this->assertTrue(app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid())['success']);
    }

    public function test_missing_or_partial_prototype_tables_do_not_break_off_warehouse(): void
    {
        $character = $this->character();
        $this->materials($character, 2);
        $this->equipment($character, 1);
        config(['nameless_relics.enabled' => false]);
        Schema::drop('player_relics');
        Schema::drop('player_nameless_equipments');
        $service = app(StorageCapacityService::class);
        $summary = $service->summary($character);
        $this->assertSame(2, $summary['material_total']);
        $this->assertSame(1, $summary['equipment_total']);
        $this->assertSame(0, $summary['relic_total']);
        $this->assertSame(0, $summary['nameless_equipment_total']);
        Schema::create('player_relics', fn ($table) => $table->id());
        $this->assertSame($summary, $service->summary($character));
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class)->get(route('inventory.index'))->assertOk()
            ->assertDontSee('鍛冶屋で整理');
    }

    public function test_one_slot_in_each_pool_keeps_both_drops_batch_result_and_replay(): void
    {
        $character = $this->character();
        $this->body($character);
        $this->materials($character, 499);
        $this->equipment($character, 298);
        config(['nameless_relics.drop_chance_bps' => 10000, 'nameless_relics.equipment_drop_chance_bps' => 10000]);
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid, 50);
        $this->assertSame(1, $result['batch_explore']['completed']);
        $this->assertCount(1, $result['relic_drops']);
        $this->assertCount(1, $result['nameless_equipment_drops']);
        $this->assertSame(99, $character->fresh()->explore_stamina);
        $summary = app(StorageCapacityService::class)->summary($character);
        $this->assertSame(500, $summary['material_total']);
        $this->assertSame(300, $summary['equipment_total']);
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid, 50));
        $this->assertSame(1, NamelessWorkshopOperation::where('character_id', $character->id)->where('action', 'ruin')->count());
        try { $service->fight($character->fresh(), 'sand', 1, false, (string) Str::uuid()); $this->fail('満杯'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('所持枠がいっぱい', $error->getMessage()); }
        $this->assertSame(99, $character->fresh()->explore_stamina);
    }

    public function test_goblin_uses_actual_material_space_at_nine_and_ten_with_weapon_drop(): void
    {
        config(['nameless_relics.relic_goblin_encounter_bps' => 10000, 'nameless_relics.equipment_drop_chance_bps' => 10000]);
        foreach ([9, 10] as $space) {
            $character = $this->character();
            $this->body($character);
            $this->materials($character, 500 - $space);
            $this->equipment($character, 298);
            $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
            $this->assertSame($space === 10 ? 'relic_goblin' : 'normal', $result['encounter_kind']);
            $this->assertCount($space === 10 ? 10 : 0, $result['relic_drops']);
            $this->assertCount(1, $result['nameless_equipment_drops']);
            $summary = app(StorageCapacityService::class)->summary($character);
            $this->assertSame($space === 10 ? 0 : 9, $summary['material_free']);
            $this->assertSame(0, $summary['equipment_free']);
        }
    }

    public function test_inventory_and_workshop_render_shared_usage_and_relic_counts(): void
    {
        $character = $this->character();
        $this->body($character);
        $this->materials($character, 499);
        $this->equipment($character, 1);
        PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('inventory.index'))->assertOk()->assertSee('遺物1個を含みます')->assertSee('名もなき武具1個を含みます');
        $summary = $response->viewData('storageSummary');
        $this->assertSame(500, $summary['material_storage_total']);
        $this->assertSame(2, $summary['equipment_storage_total']);
        $this->get(route('nameless-workshop.index', ['tab' => 'sets']))->assertOk()->assertSee('素材倉庫 500 / 500');
        $this->get(route('nameless-workshop.index'))->assertOk()->assertSee('装備倉庫 2 / 300');
    }

    public function test_high_depth_is_passed_to_body_drop_roll_and_replay_does_not_roll_again(): void
    {
        $character = $this->character();
        $this->body($character);
        \App\Models\NamelessRuinProgress::create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 100]);
        config(['nameless_relics.enemy_depth_growth' => 0, 'nameless_relics.enemy_depth_quadratic_growth' => 0]);
        $this->partialMock(\App\Services\NamelessEquipmentCollectionService::class, function ($mock): void {
            $mock->shouldReceive('dropsForTicket')->once()->withArgs(fn (int $ticket, int $depth) => $ticket >= 1 && $ticket <= 10000 && $depth === 100)->andReturn(true);
        });
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 100, false, $uuid);
        $this->assertCount(1, $result['nameless_equipment_drops']);
        $this->assertSame(2, PlayerNamelessEquipment::where('character_id', $character->id)->count());
        $this->assertSame($result, $service->fight($character, 'sand', 100, false, $uuid));
        $this->assertSame(2, PlayerNamelessEquipment::where('character_id', $character->id)->count());
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '共有倉庫検証', 'current_city_id' => $town->id,
            'money' => 10000, 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 10000, 'current_mp' => 500,
            'attack_base' => 10000, 'magic_base' => 10000, 'defense_base' => 1000, 'spirit_base' => 1000, 'speed_base' => 1000,
            'explore_stamina' => 100, 'explore_stamina_updated_at' => now()]);
    }

    private function body(Character $character): PlayerNamelessEquipment
    {
        $result = app(NamelessWorkshopService::class)->claim($character, 'weapon', '剣', (string) Str::uuid());
        return PlayerNamelessEquipment::findOrFail($result['equipment_id']);
    }

    private function materials(Character $character, int $count): void
    {
        $material = Material::create(['material_code' => 'SHARED_'.Str::uuid(), 'name' => '共有倉庫の木片', 'category' => '素材', 'rarity' => 'N', 'material_type' => 'common_drop', 'is_key_item' => false, 'is_cash_item' => false]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => $count]);
    }

    private function equipment(Character $character, int $count): void
    {
        $item = Item::create(['name' => '共有倉庫の剣', 'type' => 'weapon', 'weapon_rank' => 'C', 'sell_price' => 100]);
        CharacterItem::insert(array_fill(0, $count, ['character_id' => $character->id, 'item_id' => $item->id, 'is_equipped' => false, 'created_at' => now(), 'updated_at' => now()]));
    }
}
