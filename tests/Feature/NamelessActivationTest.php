<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\CharacterStatusService;
use App\Services\EquipmentEvolutionService;
use App\Services\EquipmentService;
use App\Services\GoldService;
use App\Services\NamelessEquipmentPowerService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicEquipmentService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use App\Services\ValmonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NamelessActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        CharacterStatusService::clearRequestCache();
    }

    public function test_production_and_staging_use_the_same_on_gate_power_and_attached_effects(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $character = $this->character();
            $workshop = app(NamelessWorkshopService::class);
            $this->assertTrue($workshop->enabled());
            $this->assertTrue($workshop->ready());
            $claimed = $workshop->claim($character, 'weapon', '剣', (string) Str::uuid());
            $body = $character->namelessEquipments()->firstOrFail();
            $body->update(['forge_level' => 99]);
            $this->assertSame(['str' => 12500, 'mag' => 3800], app(NamelessEquipmentPowerService::class)->statsAt($body, 99));
            $workshop->changeEquipment($character, $body->id, true, (string) Str::uuid());
            $relic = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'special_opener', 'rank' => 9]);
            $workshop->attach($character, $body->id, 1, $relic->id, (string) Str::uuid());
            $actor = new BattleActor('有効化検証', true, app(CharacterStatusService::class)->getFinalStats($character->fresh()), $character);
            app(NamelessRelicBattleService::class)->attach($character, $actor);
            $this->assertTrue($actor->namelessRelicsEnabled);
            $this->assertSame(.26, $actor->namelessRelicEffects['opener']);
            $armor = clone $body;
            $armor->forceFill(['kind' => 'armor', 'equipment_type' => '鎧']);
            $this->assertSame(['def' => 8000, 'spr' => 4300], $armor->performanceStats());
            $accessory = clone $body;
            $accessory->forceFill(['kind' => 'accessory', 'equipment_type' => '護符']);
            $this->assertCount(6, $accessory->performanceStats());
            $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
                ->withoutMiddleware(CheckCharacterSelected::class);
            $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertSee('攻撃 +12500');
            $equipment = $this->ordinary($character);
            $ordinaryRelic = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9]);
            $workshop->attachOrdinary($character, $equipment->id, 1, $ordinaryRelic->id, (string) Str::uuid());
            $this->assertTrue(app(EquipmentService::class)->equip($character, $equipment)['success']);
            $this->assertFalse($body->fresh()->is_equipped);
            $this->assertGreaterThan(0, app(CharacterStatusService::class)->getFinalStats($character->fresh())['relic_stat_bonuses']['str']);
            $this->assertFalse(app(GoldService::class)->canSellEquipment($equipment));
            $this->assertNotEmpty($claimed);
        }
    }

    public function test_off_stops_routes_and_effects_but_keeps_asset_guards_in_every_environment(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            config(['nameless_relics.enabled' => true]);
            $this->app->instance('env', $environment);
            $character = $this->character();
            $other = $this->character();
            $equipment = $this->ordinary($character);
            $relic = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9,
                'character_item_id' => $equipment->id, 'slot_number' => 1]);
            config(['nameless_relics.enabled' => false]);
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
            $this->assertTrue(app(NamelessRelicEquipmentService::class)->hasAttachedRelics($equipment));
            $this->assertFalse(app(GoldService::class)->canSellEquipment($equipment));
            $this->reject(fn () => app(GoldService::class)->sellEquipment($character, $equipment));
            $this->reject(fn () => $equipment->delete());
            $this->reject(fn () => $equipment->update(['character_id' => $other->id]));
            $equipment->refresh();
            $this->assertSame(0, app(ValmonService::class)->equipmentFeedExp($equipment));
            $this->assertTrue(app(NamelessWorkshopService::class)->activeRelics($character)->isEmpty());
            CharacterStatusService::clearRequestCache();
            $this->assertArrayNotHasKey('relic_baseline', app(CharacterStatusService::class)->getFinalStats($character->fresh()));
            $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
                ->withoutMiddleware(CheckCharacterSelected::class);
            $this->get(route('nameless-workshop.index'))->assertNotFound();
            $this->withSession(['_token' => 'activation-off-token'])
                ->post(route('nameless-workshop.act', ['action' => 'claim']), ['_token' => 'activation-off-token'])->assertNotFound();
            $this->assertSame($equipment->id, $relic->fresh()->character_item_id);
            app(NamelessRelicEquipmentService::class)->releaseForLoss($equipment);
            $equipment->delete();
            $this->assertNull($relic->fresh()->character_item_id);
            $this->assertNull($relic->fresh()->slot_number);
            $this->assertSame(9, $relic->fresh()->rank);
        }
    }

    public function test_off_evolution_preserves_owner_rank_and_socket_without_enabling_workshop(): void
    {
        foreach (['production', 'staging'] as $environment) {
            config(['nameless_relics.enabled' => true]);
            $this->app->instance('env', $environment);
            $character = $this->character();
            $source = $this->ordinary($character, 'SSS');
            $target = $this->ordinary($character);
            $item = $target->item;
            $target->delete();
            $relic = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9,
                'character_item_id' => $source->id, 'slot_number' => 2]);
            $recipe = 'ACTIVATION_'.strtoupper($environment);
            DB::table('weapon_evolution_recipes')->insert(['recipe_id' => $recipe, 'from_weapon_id' => $source->item->external_item_id,
                'from_weapon_name' => $source->item->name, 'to_weapon_id' => $item->external_item_id, 'to_weapon_name' => $item->name,
                'weapon_family_id' => 'ACTIVATION_TEST', 'category_id' => 'sword', 'from_rank' => 'SSS', 'to_rank' => 'EPIC',
                'same_weapon_count' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            config(['nameless_relics.enabled' => false]);
            $result = app(EquipmentEvolutionService::class)->evolve($character, 'weapon', $recipe, $source->id);
            $this->assertDatabaseMissing('character_items', ['id' => $source->id]);
            $this->assertSame($result['created_equipment_id'], $relic->fresh()->character_item_id);
            $this->assertSame($character->id, $relic->fresh()->character_id);
            $this->assertSame(2, $relic->fresh()->slot_number);
            $this->assertSame(9, $relic->fresh()->rank);
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        }
    }

    public function test_unequipped_relic_item_cannot_be_sold_or_transferred_with_flag_on_or_off(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            config(['nameless_relics.enabled' => true]);
            $character = $this->character();
            $other = $this->character();
            $equipment = $this->ordinary($character);
            $relic = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9,
                'character_item_id' => $equipment->id, 'slot_number' => 1]);
            foreach ([true, false] as $flag) {
                config(['nameless_relics.enabled' => $flag]);
                $this->assertFalse(app(GoldService::class)->canSellEquipment($equipment));
                $this->reject(fn () => app(GoldService::class)->sellEquipment($character, $equipment));
                $this->reject(fn () => $equipment->update(['character_id' => $other->id]));
                $equipment->refresh();
                $this->assertSame($character->id, $equipment->character_id);
                $this->assertSame($equipment->id, $relic->fresh()->character_item_id);
                $this->assertSame(1000000, $character->fresh()->money);
            }
        }
    }

    public function test_incomplete_migration_keeps_workshop_unavailable_even_when_on(): void
    {
        Schema::table('player_relics', fn ($table) => $table->dropColumn('growth_progress'));
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertTrue(app(NamelessWorkshopService::class)->enabled());
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        }
    }

    public function test_town_command_alias_is_available_in_production_with_complete_schema(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('nameless:install-town')->assertSuccessful();
        $this->assertNotNull(app(NamelessTownService::class)->availableTown());
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '有効化検証',
            'current_city_id' => $town->id, 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 5000, 'current_mp' => 300,
            'attack_base' => 1000, 'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000,
            'speed_base' => 1000, 'luck_base' => 10, 'money' => 1000000]);
    }

    private function ordinary(Character $character, string $rank = 'EPIC'): CharacterItem
    {
        $item = Item::query()->create(['name' => $rank.'有効化検証剣', 'external_item_id' => (string) Str::uuid(), 'type' => 'weapon',
            'rarity' => $rank, 'weapon_rank' => $rank, 'weapon_category' => 'sword', 'weapon_family_id' => 'ACTIVATION_TEST',
            'is_active' => true, 'str_bonus' => 100, 'sell_price' => 100]);
        return CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id,
            'is_equipped' => false, 'is_stored' => false]);
    }

    private function reject(callable $operation): void
    {
        try { $operation(); $this->fail('装着済み資産の消費または所有者変更が成功しました。'); }
        catch (RuntimeException $exception) { $this->assertStringContainsString('取り外して', $exception->getMessage()); }
    }
}
