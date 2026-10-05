<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\CharacterStatusService;
use App\Services\EquipmentEvolutionService;
use App\Services\EquipmentMarketService;
use App\Services\EquipmentService;
use App\Services\GoldService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicEquipmentService;
use App\Services\NamelessRelicGrowthService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use App\Services\ValmonService;
use App\Services\WeaponTraitForgeService;
use App\Services\WeaponTraitTransferService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrdinaryEquipmentRelicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('o', 32))]);
        CharacterStatusService::clearRequestCache();
    }

    public function test_sss_has_two_slots_and_epic_has_three_for_each_equipment_kind(): void
    {
        $character = $this->character();
        foreach (['weapon', 'armor', 'accessory'] as $kind) {
            foreach (['SSS' => 2, 'EPIC' => 3] as $rank => $slots) {
                $equipment = $this->ordinary($character, $kind, $rank);
                foreach (array_slice(['stat_str', 'stat_def', 'stat_mag'], 0, $slots) as $index => $effect) {
                    $this->attach($character, $equipment, $index + 1, $this->relic($character, $effect));
                }
                $this->assertSame($slots, $equipment->relics()->count());
                $relic = $this->relic($character, 'stat_spr');
                $this->reject(fn () => $this->attach($character, $equipment, $slots + 1, $relic), '遺物枠が不正');
                $this->assertFalse($relic->fresh()->isAttached());
            }
        }
        $body = $this->nameless($character);
        $this->assertSame(3, app(NamelessRelicEquipmentService::class)->slotsFor($body));
    }

    public function test_unsupported_rank_foreign_equipment_and_foreign_relic_are_rejected(): void
    {
        $character = $this->character();
        $foreign = $this->character();
        $relic = $this->relic($character, 'stat_str');
        $this->reject(fn () => $this->attach($character, $this->ordinary($character, 'weapon', 'SS'), 1, $relic), '遺物枠');
        $this->reject(fn () => $this->attach($character, $this->ordinary($foreign), 1, $relic), '所持');
        $this->reject(fn () => $this->attach($character, $this->ordinary($character), 1, $this->relic($foreign, 'stat_str')), '所持');
        $this->assertFalse($relic->fresh()->isAttached());
    }

    public function test_replace_detach_and_retry_preserve_each_relic_without_charging_gold(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $first = $this->relic($character, 'stat_str');
        $second = $this->relic($character, 'stat_def');
        $this->attach($character, $equipment, 1, $first);
        $uuid = (string) Str::uuid();
        $workshop = app(NamelessWorkshopService::class);
        $result = $workshop->attachOrdinary($character, $equipment->id, 1, $second->id, $uuid);
        $this->assertSame($result, $workshop->attachOrdinary($character, $equipment->id, 1, $second->id, $uuid));
        $this->assertFalse($first->fresh()->isAttached());
        $this->assertNull($first->fresh()->slot_number);
        $this->assertNull($second->fresh()->nameless_equipment_id);
        $this->reject(fn () => $workshop->attach($character, $this->nameless($character)->id, 1, $second->id, (string) Str::uuid()), '装着中');
        $workshop->detach($character, $second->id, (string) Str::uuid());
        $this->assertNull($second->fresh()->character_item_id);
        $this->assertNull($second->fresh()->slot_number);
        $this->assertSame(10000000, (int) $character->fresh()->money);
        $this->assertSame(2, PlayerRelic::query()->count());
    }

    public function test_duplicate_and_exclusive_rules_cover_both_equipment_families_on_attach_and_equip(): void
    {
        $character = $this->character();
        $weapon = $this->ordinary($character);
        $armor = $this->ordinary($character, 'armor');
        $body = $this->nameless($character, 'accessory');
        $this->attach($character, $weapon, 1, $this->relic($character, 'stat_str'));
        $this->attach($character, $armor, 1, $this->relic($character, 'stat_str'));
        $this->assertTrue(app(EquipmentService::class)->equip($character, $weapon)['success']);
        $result = app(EquipmentService::class)->equip($character, $armor);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('同じ遺物効果', $result['message']);
        $this->assertTrue($weapon->fresh()->is_equipped);
        $this->assertFalse($armor->fresh()->is_equipped);
        $workshop = app(NamelessWorkshopService::class);
        $workshop->changeEquipment($character, $body->id, true, (string) Str::uuid());
        $this->reject(fn () => $workshop->attach($character, $body->id, 2, $this->relic($character, 'stat_str')->id, (string) Str::uuid()), '同じ遺物効果');
    }

    public function test_reserve_can_overlap_but_switching_into_a_conflicting_loadout_is_atomic(): void
    {
        $character = $this->character();
        $weapon = $this->ordinary($character);
        $accessory = $this->nameless($character, 'accessory');
        $workshop = app(NamelessWorkshopService::class);
        $this->attach($character, $weapon, 1, $this->relic($character, 'brand_dragon'));
        $workshop->attach($character, $accessory->id, 1, $this->relic($character, 'brand_beast')->id, (string) Str::uuid());
        app(EquipmentService::class)->equip($character, $weapon);
        $this->reject(fn () => $workshop->changeEquipment($character, $accessory->id, true, (string) Str::uuid()), '一種類');
        $this->assertFalse($accessory->fresh()->is_equipped);
        $this->assertTrue($weapon->fresh()->is_equipped);
        $this->reject(fn () => $this->attach($character, $weapon->fresh(), 2, $this->relic($character, 'brand_beast')), '一種類');
    }

    public function test_normal_stats_battle_effects_and_preview_use_only_equipped_relics_with_shared_cap(): void
    {
        $character = $this->character();
        $weapon = $this->ordinary($character);
        $armor = $this->ordinary($character, 'armor');
        $this->attach($character, $weapon, 1, $this->relic($character, 'stat_str'));
        $this->attach($character, $weapon, 2, $this->relic($character, 'compound_valor'));
        $this->attach($character, $weapon, 3, $this->relic($character, 'special_drain'));
        $this->attach($character, $armor, 1, $this->relic($character, 'compound_polarity'));
        app(EquipmentService::class)->equip($character, $weapon);
        app(EquipmentService::class)->equip($character, $armor);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertEquals(.30, app(NamelessWorkshopService::class)->equippedStatRates($character)['str']);
        $this->assertSame((int) floor(round($stats['relic_baseline']['str'] * 1.3, 6)), $stats['str']);
        $this->assertSame($stats['str'], app(CharacterStatusService::class)->weaponEffectivePreview($character, $weapon)['str']);
        $actor = new BattleActor('検証', true, $stats);
        app(NamelessRelicBattleService::class)->attach($character, $actor);
        $this->assertGreaterThan(0, $actor->namelessRelicEffects['drain']);
        app(EquipmentService::class)->unequip($character, $weapon);
        $actor = new BattleActor('控え', true, []);
        app(NamelessRelicBattleService::class)->attach($character, $actor);
        $this->assertArrayNotHasKey('drain', $actor->namelessRelicEffects);
    }

    public function test_attached_normal_relic_is_not_a_growth_feed_or_discard_ingredient(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $attached = $this->relic($character, 'stat_str', 1);
        $target = $this->relic($character, 'stat_str', 1);
        $this->attach($character, $equipment, 1, $attached);
        $growth = app(NamelessRelicGrowthService::class);
        $this->assertTrue($growth->candidates($target, collect([$attached->fresh()]))->isEmpty());
        $this->reject(fn () => $growth->preview($character, $target->id, [$attached->id]), '装着中');
        $workshop = app(NamelessWorkshopService::class);
        $this->reject(fn () => $workshop->discard($character, $attached->id, (string) Str::uuid()), '装着中');
        $this->reject(fn () => $workshop->previewFeed($character, $this->nameless($character)->id, [], [$attached->id], 0, false), '装着中');
        $this->assertDatabaseHas('player_relics', ['id' => $attached->id, 'character_item_id' => $equipment->id]);
    }

    public function test_growth_on_normal_equipment_updates_stats_and_rejects_a_stale_binding(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character, 'accessory');
        $target = $this->relic($character, 'stat_str', 1);
        $this->attach($character, $equipment, 1, $target);
        app(EquipmentService::class)->equip($character, $equipment);
        $sources = [$this->relic($character, 'stat_str', 1)->id, $this->relic($character, 'stat_str', 1)->id];
        $growth = app(NamelessRelicGrowthService::class);
        $preview = $growth->preview($character, $target->id, $sources);
        $before = app(CharacterStatusService::class)->getFinalStats($character)['str'];
        $result = $growth->grow($character, $target->id, $sources, $preview['confirmation_hash'], (string) Str::uuid());
        $this->assertTrue($result['rank_up']);
        $this->assertSame($equipment->id, $target->fresh()->character_item_id);
        $this->assertGreaterThan($before, app(CharacterStatusService::class)->getFinalStats($character->fresh())['str']);
        $sources = [$this->relic($character, 'stat_str', 2)->id];
        $preview = $growth->preview($character, $target->id, $sources);
        app(NamelessWorkshopService::class)->detach($character, $target->id, (string) Str::uuid());
        $this->reject(fn () => $growth->grow($character, $target->id, $sources, $preview['confirmation_hash'], (string) Str::uuid()), '所持状態');
        $this->assertDatabaseHas('player_relics', ['id' => $sources[0]]);
    }

    public function test_sell_market_trait_material_and_valmon_feed_are_blocked_until_detached(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $base = $this->ordinary($character);
        $relic = $this->relic($character, 'stat_str');
        $this->attach($character, $equipment, 1, $relic);
        $this->assertFalse(app(GoldService::class)->canSellEquipment($equipment));
        $this->reject(fn () => app(GoldService::class)->sellEquipment($character, $equipment), '取り外して');
        $this->reject(fn () => app(EquipmentMarketService::class)->listEquipment($character, $equipment, 1000), '取り外して');
        $this->reject(fn () => app(WeaponTraitForgeService::class)->forge($character, 'engraving_forge', $base->id, $equipment->id), '取り外して');
        $this->reject(fn () => app(WeaponTraitTransferService::class)->transfer($character, 'engraving_transfer', $base->id, $equipment->id), '取り外して');
        $this->assertSame(0, app(ValmonService::class)->equipmentFeedExp($equipment));
        $this->assertSame(10000000, (int) $character->fresh()->money);
        app(NamelessWorkshopService::class)->detach($character, $relic->id, (string) Str::uuid());
        $this->assertTrue(app(GoldService::class)->canSellEquipment($equipment));
        app(GoldService::class)->sellEquipment($character, $equipment);
        $this->assertDatabaseMissing('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseHas('player_relics', ['id' => $relic->id, 'character_item_id' => null]);
    }

    public function test_evolution_inherits_selected_source_relics_in_the_same_slots(): void
    {
        $character = $this->character();
        $source = $this->ordinary($character, 'weapon', 'SSS');
        $destination = $this->ordinary($character, 'weapon', 'EPIC');
        $to = $destination->item;
        $destination->delete();
        foreach ([1 => 'stat_str', 2 => 'special_drain'] as $slot => $effect) {
            $this->attach($character, $source, $slot, $this->relic($character, $effect, 3));
        }
        app(EquipmentService::class)->equip($character, $source);
        $relicIds = $source->relics()->orderBy('slot_number')->pluck('id')->all();
        DB::table('weapon_evolution_recipes')->insert(['recipe_id' => 'RELIC_EVOLUTION_TEST', 'from_weapon_id' => $source->item->external_item_id,
            'from_weapon_name' => $source->item->name, 'to_weapon_id' => $to->external_item_id, 'to_weapon_name' => $to->name,
            'weapon_family_id' => 'RELIC_TEST', 'category_id' => 'sword', 'from_rank' => 'SSS', 'to_rank' => 'EPIC',
            'same_weapon_count' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $result = app(EquipmentEvolutionService::class)->evolve($character, 'weapon', 'RELIC_EVOLUTION_TEST', $source->id);
        $evolved = CharacterItem::query()->findOrFail($result['created_equipment_id']);
        $this->assertTrue($evolved->is_equipped);
        $this->assertSame($relicIds, $evolved->relics()->orderBy('slot_number')->pluck('id')->all());
        $this->assertSame([1, 2], $evolved->relics()->orderBy('slot_number')->pluck('slot_number')->all());
        $this->assertDatabaseMissing('character_items', ['id' => $source->id]);
        $this->assertSame(3, app(NamelessRelicEquipmentService::class)->slotsFor($evolved));
    }

    public function test_guards_preserve_assets_when_disabled_and_loss_releases_relic_without_changing_loss_rules(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $relic = $this->relic($character, 'stat_str');
        $this->attach($character, $equipment, 1, $relic);
        $foreign = $this->character();
        config(['nameless_relics.enabled' => false]);
        $this->reject(fn () => $equipment->delete(), '取り外して');
        $this->reject(fn () => $equipment->update(['character_id' => $foreign->id]), '取り外して');
        $this->assertTrue(app(NamelessWorkshopService::class)->activeRelics($character)->isEmpty());
        $equipment->refresh();
        app(NamelessRelicEquipmentService::class)->releaseForLoss($equipment);
        $equipment->delete();
        $this->assertFalse($relic->fresh()->isAttached());
        $this->assertNull($relic->fresh()->slot_number);
        $this->assertSame(9, $relic->fresh()->rank);
    }

    public function test_equipment_cards_show_only_attached_relics_in_closed_accordions(): void
    {
        $character = $this->character();
        $expected = [];
        foreach (['weapon', 'armor', 'accessory'] as $kind) {
            $equipment = $this->ordinary($character, $kind, $kind === 'armor' ? 'SSS' : 'EPIC');
            $relic = $this->relic($character, 'stat_str');
            $this->attach($character, $equipment, 1, $relic);
            if ($kind === 'armor') {
                $equipment->update(['is_stored' => true]);
            }
            $expected[] = $equipment;
        }
        $empty = $this->ordinary($character);
        $foreign = $this->ordinary($this->character());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('equipment.index'));
        $response->assertOk()->assertSee('装着遺物（1／2）')->assertSee('装着遺物（1／3）')
            ->assertSee('剛力の遺物')->assertSee($relic->effectSummary());
        foreach ($expected as $equipment) {
            $response->assertSee('data-equipment-attached-relics="'.$equipment->id.'"', false);
        }
        $response->assertDontSee('data-equipment-attached-relics="'.$empty->id.'"', false)
            ->assertDontSee('data-equipment-attached-relics="'.$foreign->id.'"', false);
        $this->assertSame(3, substr_count($response->getContent(), 'data-equipment-attached-relics='));
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-equipment-attached-relics[^>]*\\sopen(?:\\s|>)/', $response->getContent());
    }

    public function test_nameless_owned_cards_show_relics_without_nesting_details_in_links(): void
    {
        $character = $this->character();
        $body = $this->nameless($character);
        $empty = $this->nameless($character, 'accessory');
        $foreign = $this->nameless($this->character());
        $relic = $this->relic($character, 'stat_agi', 7);
        app(NamelessWorkshopService::class)->attach($character, $body->id, 2, $relic->id, (string) Str::uuid());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('equipment.index'));
        $response->assertOk()->assertSee('装着遺物（1／3）')->assertSee($relic->displayName())
            ->assertSee($relic->effectSummary())->assertSee('data-equipment-attached-relics="'.$body->id.'"', false)
            ->assertDontSee('data-equipment-attached-relics="'.$empty->id.'"', false)
            ->assertDontSee('data-nameless-owned-equipment="'.$foreign->id.'"', false);
        $this->assertMatchesRegularExpression('/data-nameless-owned-equipment="'.$body->id.'"[^>]*>.*?<\/a>\s*<details/s', $response->getContent());
    }

    public function test_relic_sale_notice_links_to_its_own_target_and_disappears_after_detach(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $empty = $this->ordinary($character);
        $relic = $this->relic($character, 'stat_str');
        $this->attach($character, $equipment, 1, $relic);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('equipment.index'));
        $response->assertOk()->assertSee('遺物装着中は売却できません。')->assertSee('遺物を取り外す')
            ->assertSee('title="遺物を取り外してから売却してください"', false)
            ->assertSee(route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $equipment->id]))
            ->assertDontSee('data-relic-sale-restriction="'.$empty->id.'"', false);
        $this->assertFalse(app(GoldService::class)->canSellEquipment($equipment));
        config(['nameless_relics.enabled' => false]);
        $this->get(route('equipment.index'))->assertOk()->assertSee('遺物装着中は売却できません。')
            ->assertDontSee('遺物を取り外す');
        config(['nameless_relics.enabled' => true]);
        app(NamelessWorkshopService::class)->detach($character, $relic->id, (string) Str::uuid());
        $this->get(route('equipment.index'))->assertOk()->assertDontSee('遺物装着中は売却できません。')
            ->assertDontSee('遺物を取り外す');
        $this->assertTrue(app(GoldService::class)->canSellEquipment($equipment->fresh()));
    }

    public function test_relic_feed_link_opens_the_selected_or_fallback_nameless_forge(): void
    {
        $character = $this->character();
        $first = $this->nameless($character);
        $selected = $this->nameless($character, 'accessory');
        $ordinary = $this->ordinary($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        foreach ([['equipment' => $selected->id], ['character_item' => $ordinary->id]] as $selection) {
            $target = isset($selection['equipment']) ? $selected : $first;
            $query = ['gear_query' => '一致しない名前'];
            $url = route('nameless-workshop.index', $query + ['tab' => 'workshop', 'equipment' => $target->id]);
            $this->get(route('nameless-workshop.index', $query + ['tab' => 'sets'] + $selection))
                ->assertOk()->assertSee('不要な遺物を吸収する')->assertSee($url.'#forge')
                ->assertDontSee('#absorb');
            $this->get($url)->assertOk()->assertViewHas('tab', 'workshop')
                ->assertViewHas('selectedEquipment', fn ($body) => $body->id === $target->id)
                ->assertSee('id="forge"', false);
        }
    }

    public function test_relic_feed_link_is_hidden_without_a_nameless_target(): void
    {
        $character = $this->character();
        $ordinary = $this->ordinary($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $ordinary->id]))
            ->assertOk()->assertDontSee('不要な遺物を吸収する');
    }

    public function test_workshop_shows_the_owned_normal_target_slot_count_and_posts_to_the_normal_action(): void
    {
        $character = $this->character();
        foreach (['SSS' => 2, 'EPIC' => 3] as $rank => $slots) {
            $equipment = $this->ordinary($character, 'armor', $rank);
            $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
            $response = $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $equipment->id]));
            $response->assertOk()->assertSee('遺物は'.$slots.'つまで。')->assertSee(route('nameless-workshop.act', 'attach-ordinary'), false);
            $this->assertSame($slots, substr_count($response->getContent(), 'class="relic-slot"'));
            $relic = $this->relic($character, 'stat_str');
            $this->post(route('nameless-workshop.act', 'attach-ordinary'), ['character_item_id' => $equipment->id, 'slot' => $slots,
                'relic_id' => $relic->id, 'request_uuid' => (string) Str::uuid(), 'workshop_tab' => 'sets'])
                ->assertRedirect(route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $equipment->id]));
            $this->assertDatabaseHas('player_relics', ['id' => $relic->id, 'character_item_id' => $equipment->id, 'slot_number' => $slots]);
        }
        $foreign = $this->ordinary($this->character());
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $foreign->id]))->assertNotFound();
    }

    public function test_migration_preserves_named_bindings_and_enforces_normal_socket_uniqueness(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.relic_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('relic_migration');
        try {
            foreach (['characters', 'character_items', 'player_nameless_equipments'] as $table) {
                Schema::create($table, fn (Blueprint $table) => $table->id());
                DB::table($table)->insert(['id' => 1]);
            }
            Schema::create('player_relics', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('effect_key');
                $table->unsignedTinyInteger('rank');
                $table->unsignedTinyInteger('growth_progress')->default(0);
                $table->boolean('is_locked')->default(false);
                $table->foreignId('nameless_equipment_id')->nullable()->constrained('player_nameless_equipments')->nullOnDelete();
                $table->unsignedTinyInteger('slot_number')->nullable();
                $table->timestamps();
                $table->unique(['nameless_equipment_id', 'slot_number'], 'player_relic_socket_unique');
            });
            DB::table('player_relics')->insert(['id' => 1, 'character_id' => 1, 'effect_key' => 'stat_str', 'rank' => 3,
                'growth_progress' => 1, 'is_locked' => true, 'nameless_equipment_id' => 1, 'slot_number' => 2]);
            $before = (array) DB::table('player_relics')->first();
            $migration = require database_path('migrations/2026_10_05_080000_add_ordinary_equipment_relic_sockets.php');
            $migration->up();
            $this->assertSame($before + ['character_item_id' => null], (array) DB::table('player_relics')->first());
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            DB::table('player_relics')->insert(['id' => 2, 'character_id' => 1, 'effect_key' => 'stat_def', 'rank' => 1,
                'character_item_id' => 1, 'slot_number' => 1]);
            try {
                DB::table('player_relics')->insert(['id' => 3, 'character_id' => 1, 'effect_key' => 'stat_mag', 'rank' => 1,
                    'character_item_id' => 1, 'slot_number' => 1]);
                $this->fail('同じ枠に2つの遺物が保存されました。');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('UNIQUE', $e->getMessage());
            }
            $this->reject(fn () => $migration->down(), '装着中');
            DB::table('player_relics')->where('id', 2)->delete();
            $migration->down();
            $this->assertSame($before, (array) DB::table('player_relics')->first());
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('relic_migration');
        }
    }

    public function test_resource_changes_never_heal_and_detach_clamps_current_hp(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character, 'accessory');
        app(EquipmentService::class)->equip($character, $equipment);
        $relic = $this->relic($character, 'stat_hp');
        $this->attach($character, $equipment, 1, $relic);
        $this->assertSame(5000, (int) $character->fresh()->current_hp);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $character->update(['current_hp' => $stats['max_hp']]);
        app(NamelessWorkshopService::class)->detach($character, $relic->id, (string) Str::uuid());
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame($stats['max_hp'], (int) $character->fresh()->current_hp);
        $this->assertSame(300, (int) $character->fresh()->current_mp);
    }

    public function test_accessory_shop_preview_matches_the_actual_swap_with_only_normal_relic_equipment(): void
    {
        $character = $this->character();
        $weapon = $this->ordinary($character);
        $armor = $this->ordinary($character, 'armor');
        $old = $this->ordinary($character, 'accessory');
        $incoming = $this->ordinary($character, 'accessory');
        $incoming->item->update(['hp_bonus' => 37, 'mp_bonus' => 19, 'str_bonus' => 111, 'mag_bonus' => 73,
            'def_bonus' => 91, 'spr_bonus' => 83, 'agi_bonus' => 59, 'luk_bonus' => 43]);
        $this->attach($character, $weapon, 1, $this->relic($character, 'stat_str'));
        $this->attach($character, $armor, 1, $this->relic($character, 'stat_def'));
        $this->attach($character, $old, 1, $this->relic($character, 'stat_all'));
        foreach ([$weapon, $armor, $old] as $equipment) {
            $this->assertTrue(app(EquipmentService::class)->equip($character, $equipment)['success']);
        }
        $preview = app(CharacterStatusService::class)->equipmentSwapPreviewForItem($character->fresh(), $incoming->item);
        $this->assertTrue(app(EquipmentService::class)->equip($character, $incoming)['success']);
        $actual = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        foreach (['hp' => 'max_hp', 'mp' => 'max_mp', 'str' => 'str', 'def' => 'def', 'mag' => 'mag', 'spr' => 'spr', 'agi' => 'agi', 'luk' => 'luk'] as $key => $finalKey) {
            $this->assertSame($actual[$finalKey], $preview['after_stats'][$key], $key);
        }
    }

    public function test_disabled_feature_ignores_ordinary_relics_in_all_environments(): void
    {
        $character = $this->character();
        $equipment = $this->ordinary($character);
        $this->attach($character, $equipment, 1, $this->relic($character, 'stat_str'));
        app(EquipmentService::class)->equip($character, $equipment);
        config(['nameless_relics.enabled' => false]);
        CharacterStatusService::clearRequestCache();
        $baseline = app(CharacterStatusService::class)->getFinalStats($character->fresh())['str'];
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            CharacterStatusService::clearRequestCache();
            $this->assertSame($baseline, app(CharacterStatusService::class)->getFinalStats($character->fresh())['str']);
            $this->assertTrue(app(NamelessWorkshopService::class)->activeRelics($character)->isEmpty());
        }
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '通常装備遺物検証', 'current_city_id' => $town->id,
            'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 5000, 'current_mp' => 300, 'attack_base' => 1000,
            'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000, 'speed_base' => 1000, 'luck_base' => 10, 'money' => 10000000]);
    }

    private function ordinary(Character $character, string $kind = 'weapon', string $rank = 'EPIC'): CharacterItem
    {
        $id = (string) Str::uuid();
        $item = Item::query()->create(['name' => $rank.'検証'.$kind, 'external_item_id' => $id, 'type' => $kind, 'rarity' => $rank,
            $kind.'_rank' => $rank, 'weapon_category' => 'sword', 'weapon_family_id' => 'RELIC_TEST', 'is_active' => true,
            'str_bonus' => $kind === 'weapon' ? 100 : 0, 'def_bonus' => $kind === 'armor' ? 100 : 0, 'sell_price' => 100]);
        return CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'is_equipped' => false, 'is_stored' => false]);
    }

    private function nameless(Character $character, string $kind = 'weapon'): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => $kind,
            'equipment_type' => $kind === 'accessory' ? '指輪' : '剣', 'acquisition_source' => 'ruin',
            'forge_level' => 0, 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]);
    }

    private function relic(Character $character, string $effect, int $rank = 9): PlayerRelic
    {
        return PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => $effect, 'rank' => $rank]);
    }

    private function attach(Character $character, CharacterItem $equipment, int $slot, PlayerRelic $relic): void
    {
        app(NamelessWorkshopService::class)->attachOrdinary($character, $equipment->id, $slot, $relic->id, (string) Str::uuid());
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('拒否されるべき操作が成功しました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
