<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\Item;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\GoldService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicEquipmentService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NamelessProductionOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_and_staging_remain_off_without_feature_tables_even_when_flag_is_true(): void
    {
        $this->removeFeatureTables();
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            foreach ([false, true] as $flag) {
                config(['nameless_relics.enabled' => $flag]);
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->assertSame($flag, app(NamelessWorkshopService::class)->enabled());
                $this->assertFalse(app(NamelessWorkshopService::class)->ready());
                $this->assertNull(app(NamelessTownService::class)->availableTown());
                $this->assertFalse(app(NamelessRelicEquipmentService::class)->ordinarySchemaReady());
                $this->assertFalse(app(NamelessRelicEquipmentService::class)->hasAttachedRelics(new CharacterItem(['id' => 1])));
                foreach (DB::getQueryLog() as $query) {
                    $this->assertDoesNotMatchRegularExpression('/(?:from|into|update|join)\s+[\"`]?player_relics\b/i', $query['query']);
                }
                DB::disableQueryLog();
            }
        }
    }

    public function test_authenticated_feature_routes_are_blocked_without_feature_schema(): void
    {
        $character = $this->character();
        $this->removeFeatureTables();
        $this->app->instance('env', 'production');
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index'))->assertNotFound();
        $this->get(route('nameless-workshop.result', ['uuid' => Str::uuid()]))->assertNotFound();
        $this->get(route('nameless-workshop.return', ['tab' => 'town']))->assertNotFound();
        $this->withSession(['_token' => 'nameless-off-test-token'])
            ->post(route('nameless-workshop.act', ['action' => 'ruin']), ['_token' => 'nameless-off-test-token'])->assertNotFound();
    }

    public function test_normal_equipment_stats_display_and_sale_work_with_no_relic_schema(): void
    {
        $character = $this->character();
        $item = Item::query()->create(['name' => '通常武器OFF検証', 'type' => 'weapon', 'weapon_rank' => 'SSS',
            'str_bonus' => 30, 'price' => 1000, 'sell_price' => 500, 'is_active' => true]);
        $equipment = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        $this->removeFeatureTables();
        $this->app->instance('env', 'production');
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        CharacterStatusService::clearRequestCache();
        $before = app(CharacterStatusService::class)->getFinalStats($character);
        $this->assertArrayNotHasKey('relic_baseline', $before);
        $this->assertTrue(app(EquipmentService::class)->equip($character, $equipment)['success']);
        CharacterStatusService::clearRequestCache();
        $equipped = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertGreaterThan($before['str'], $equipped['str']);
        $actor = new BattleActor('OFF検証', true, $equipped + ['hp' => 100, 'mp' => 10], $character);
        app(NamelessRelicBattleService::class)->attach($character, $actor);
        $this->assertFalse($actor->namelessRelicsEnabled);
        $this->assertSame([], $actor->namelessRelicEffects);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('equipment.index'))->assertOk()->assertDontSee('data-equipment-attached-relics', false);
        $this->get(route('city.index'))->assertOk()->assertDontSee('data-nameless-town-entry', false);
        $this->get(route('blacksmith.traits.index'))->assertOk()
            ->assertDontSee('遺物装着中')->assertDontSee('遺物を取り外してください');
        $this->assertTrue(app(EquipmentService::class)->unequip($character, $equipment->fresh())['success']);
        $equipment->refresh();
        $this->assertTrue(app(GoldService::class)->canSellEquipment($equipment));
        app(GoldService::class)->sellEquipment($character, $equipment);
        $this->assertDatabaseMissing('character_items', ['id' => $equipment->id]);
    }

    private function removeFeatureTables(): void
    {
        // Only the isolated in-memory test database is changed.
        foreach (['player_relics', 'nameless_workshop_operations', 'nameless_equipment_discoveries', 'nameless_ruin_progress'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function character(): Character
    {
        $city = City::query()->create(['name' => '通常都市OFF検証', 'sort_order' => 10, 'is_initial' => true]);
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '機能OFF検証',
            'current_city_id' => $city->id, 'highest_city_id' => $city->id, 'hp_base' => 1000, 'mp_base' => 100,
            'current_hp' => 1000, 'current_mp' => 100, 'attack_base' => 100, 'magic_base' => 100,
            'defense_base' => 100, 'spirit_base' => 100, 'speed_base' => 100, 'luck_base' => 10, 'money' => 1000]);
    }
}
