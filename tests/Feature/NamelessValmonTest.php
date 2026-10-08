<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\GameSetting;
use App\Models\PlayerValmon;
use App\Models\PlayerValmonEgg;
use App\Models\PublicLog;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Models\ValmonSpawnRegion;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use App\Services\ValmonService;
use Database\Seeders\NamelessValmonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class NamelessValmonTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\NamelessRuinRewardReferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['nameless_relics.enabled' => true, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.cleared_boss_encounter_bps' => 0,
            'nameless_relics.drop_chance_bps' => 0, 'nameless_relics.boss_drop_chance_bps' => 0]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
        $this->seed(NamelessValmonSeeder::class);
    }

    public function test_registration_has_two_normal_species_per_zone_three_rare_species_and_valid_art(): void
    {
        $definitions = config('nameless_valmons.masters');
        $this->assertCount(15, $definitions);
        $this->assertSame(3, collect($definitions)->where('rarity', 'rare')->count());
        foreach (array_keys(config('nameless_ruins')) as $zone) {
            $this->assertSame(2, collect($definitions)->where('zone', $zone)->where('rarity', 'normal')->count());
        }
        foreach ($definitions as $key => $definition) {
            $master = ValmonMaster::query()->where('valmon_key', $key)->firstOrFail();
            $this->assertSame($definition['name'], $master->name);
            $this->assertTrue($master->is_active);
            $this->assertFalse($master->is_starter);
            $image = getimagesize(public_path($master->image_path));
            $this->assertSame([300, 300, 'image/webp'], [$image[0], $image[1], $image['mime']]);
            $this->assertFalse(ValmonSpawnRegion::query()->where('valmon_master_id', $master->id)->exists());
        }
    }

    public function test_missing_release_art_stops_registration_before_overwriting_existing_masters(): void
    {
        $master = ValmonMaster::where('valmon_key', 'ruin_sunakor')->firstOrFail();
        $master->update(['name' => '既存名称を保持']);
        config(['nameless_valmons.masters.ruin_noxia.no' => 999]);
        try {
            $this->seed(NamelessValmonSeeder::class);
            $this->fail('Missing artwork was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('画像が不足', $exception->getMessage());
        }
        $this->assertSame('既存名称を保持', $master->fresh()->name);
        $this->assertSame(15, ValmonMaster::count());
    }

    public function test_reseeding_preserves_ids_ownership_eggs_and_unrelated_masters_and_spawns(): void
    {
        $character = $this->character();
        $legacy = ValmonMaster::create(['valmon_key' => 'ameharu', 'name' => '旧相棒',
            'image_path' => 'images/valmon/new/valmon_022.webp', 'rarity' => 'normal', 'is_active' => true]);
        $spawn = ValmonSpawnRegion::create(['valmon_master_id' => $legacy->id,
            'city_id' => $character->current_city_id, 'spawn_weight' => 4321, 'is_active' => true]);
        $owned = PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $legacy->id,
            'is_partner' => true, 'level' => 17, 'affection' => 42, 'obtained_at' => now()]);
        $egg = $this->egg($character, $legacy);
        $owned->refresh();
        $egg->refresh();
        $spawn->refresh();
        $before = ValmonMaster::query()->pluck('id', 'valmon_key')->all();
        $this->seed(NamelessValmonSeeder::class);
        $this->seed(NamelessValmonSeeder::class);
        $this->assertSame($before, ValmonMaster::query()->pluck('id', 'valmon_key')->all());
        $this->assertSame('images/valmon/new/valmon_022.webp', $legacy->fresh()->image_path);
        $this->assertSame('旧相棒', $legacy->fresh()->name);
        $this->assertSame($owned->getAttributes(), $owned->fresh()->getAttributes());
        $this->assertSame($egg->getAttributes(), $egg->fresh()->getAttributes());
        $this->assertSame($spawn->getAttributes(), $spawn->fresh()->getAttributes());
    }

    public function test_fixed_probability_slots_and_exclusions_use_one_query_even_with_many_owned_species(): void
    {
        $character = $this->character();
        $this->assertSame('ruin_hoshiriru', $this->candidate($character, 'observatory', 200)->valmon_key);
        $this->assertSame('ruin_suisemu', $this->candidate($character, 'observatory', 201)->valmon_key);
        $this->assertSame('ruin_suisemu', $this->candidate($character, 'observatory', 400)->valmon_key);
        $this->assertSame('ruin_astrei', $this->candidate($character, 'observatory', 401)->valmon_key);
        $this->assertSame('ruin_astrei', $this->candidate($character, 'observatory', 420)->valmon_key);
        $this->assertNull($this->candidate($character, 'observatory', 421));
        $this->assertNull($this->candidate($character, 'observatory', 1000000));
        $first = ValmonMaster::where('valmon_key', 'ruin_hoshiriru')->firstOrFail();
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $first->id, 'obtained_at' => now()]);
        $second = ValmonMaster::where('valmon_key', 'ruin_suisemu')->firstOrFail();
        $egg = $this->egg($character, $second, ['stored_at' => now()]);
        // More ownership rows must not cause row-per-species queries or exceed SQLite parameter limits.
        $rows = [];
        foreach (range(1, 1100) as $no) {
            $master = ValmonMaster::create(['valmon_key' => 'unrelated_'.$no, 'name' => '対象外', 'rarity' => 'normal']);
            $rows[] = ['character_id' => $character->id, 'valmon_master_id' => $master->id, 'obtained_at' => now()];
        }
        PlayerValmon::insert($rows);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame('ruin_astrei', $this->candidate($character, 'observatory', 401)->valmon_key);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNull($this->candidate($character, 'observatory', 1));
        $this->assertNull($this->candidate($character, 'observatory', 201));
        $this->assertNull($this->candidate($character, 'observatory', 421));
        ValmonMaster::where('valmon_key', 'ruin_astrei')->update(['is_active' => false]);
        $this->assertNull($this->candidate($character, 'observatory', 401));
        $egg->update(['is_lost' => true]);
        $this->assertSame('ruin_suisemu', $this->candidate($character, 'observatory', 201)->valmon_key);
        $this->assertSame('ruin_sunakor', $this->candidate($character, 'sand', 1)->valmon_key);
        $this->assertNull($this->candidate($character, 'invalid'));
    }

    public function test_ruins_and_normal_exploration_share_the_daily_limit(): void
    {
        $character = $this->character();
        $service = $this->forceEggRoll();
        $area = Area::create(['name' => '通常探索試験', 'slug' => 'ruin-egg-daily', 'city_id' => $character->current_city_id]);
        $found = $service->tryFindRuinEgg($character, 'water');
        $this->assertNotNull($found);
        $egg = PlayerValmonEgg::findOrFail($found['egg_id']);
        $this->assertSame((int) $character->current_city_id, (int) $egg->found_city_id);
        $this->assertNull($egg->found_area_id);
        $this->assertNull($egg->found_exploration_state_id);
        $this->assertNull($service->tryFindRuinEgg($character, 'sand'));
        $this->assertNull($service->tryFindEgg($character, $area, null));
        $this->assertSame(1, PlayerValmonEgg::where('character_id', $character->id)->count());
        $this->assertStringContainsString('沈水の水路', PublicLog::where('type', 'valmon')->firstOrFail()->message);
        $other = $this->character();
        $this->egg($other, ValmonMaster::firstOrFail(), ['is_lost' => true, 'found_at' => now()]);
        $this->assertNull($service->tryFindRuinEgg($other, 'sand')); // A lost normal egg still uses today's allowance.
    }

    public function test_every_zone_has_the_approved_fixed_slots_and_no_depth_dependency(): void
    {
        $character = $this->character();
        foreach (array_keys(config('nameless_ruins')) as $zone) {
            $definitions = array_values(array_filter(config('nameless_valmons.masters'), fn ($row) => $row['zone'] === $zone));
            $this->assertSame($definitions[0]['name'], $this->candidate($character, $zone, 1)->name);
            $this->assertSame($definitions[0]['name'], $this->candidate($character, $zone, 200)->name);
            $this->assertSame($definitions[1]['name'], $this->candidate($character, $zone, 201)->name);
            $this->assertSame($definitions[1]['name'], $this->candidate($character, $zone, 400)->name);
            if (isset($definitions[2])) {
                $this->assertSame($definitions[2]['name'], $this->candidate($character, $zone, 401)->name);
                $this->assertSame($definitions[2]['name'], $this->candidate($character, $zone, 420)->name);
            } else {
                $this->assertNull($this->candidate($character, $zone, 401));
            }
            $this->assertNull($this->candidate($character, $zone, 421));
        }
    }

    public function test_losing_ticket_does_not_read_player_tables_or_take_a_character_lock(): void
    {
        $character = $this->character();
        app(GameSettingService::class)->getFloat('valmon.egg_rate_multiplier', 1.0);
        $service = \Mockery::mock(ValmonService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('ruinEggTicket')->andReturn(1000000);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($service->tryFindRuinEgg($character, 'water'));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_existing_admin_multiplier_scales_fixed_slots_without_redistributing_them(): void
    {
        $character = $this->character();
        GameSetting::query()->updateOrCreate(['setting_key' => 'valmon.egg_rate_multiplier'], ['value' => '2', 'value_type' => 'float']);
        app(GameSettingService::class)->flush();
        $this->assertSame('ruin_hoshiriru', $this->candidate($character, 'observatory', 400)->valmon_key);
        $this->assertSame('ruin_suisemu', $this->candidate($character, 'observatory', 800)->valmon_key);
        $this->assertSame('ruin_astrei', $this->candidate($character, 'observatory', 801)->valmon_key);
        $this->assertSame('ruin_astrei', $this->candidate($character, 'observatory', 840)->valmon_key);
        $this->assertNull($this->candidate($character, 'observatory', 841));
        $this->expectException(RuntimeException::class);
        $this->candidate($character, 'observatory', 0);
    }

    public function test_zero_multiplier_disabled_feature_and_wrong_town_do_not_award_eggs(): void
    {
        $character = $this->character();
        $service = $this->forceEggRoll();
        GameSetting::query()->updateOrCreate(['setting_key' => 'valmon.egg_rate_multiplier'], ['value' => '0', 'value_type' => 'float']);
        app(GameSettingService::class)->flush();
        $this->assertNull($service->tryFindRuinEgg($character, 'sand'));
        GameSetting::where('setting_key', 'valmon.egg_rate_multiplier')->delete();
        app(GameSettingService::class)->flush();
        config(['nameless_relics.enabled' => false]);
        $this->assertNull($service->tryFindRuinEgg($character, 'sand'));
        config(['nameless_relics.enabled' => true]);
        $character->update(['current_city_id' => null]);
        $this->assertNull($service->tryFindRuinEgg($character, 'sand'));
        $this->assertSame(0, PlayerValmonEgg::count());
    }

    public function test_batch_keeps_the_first_egg_and_replay_never_awards_twice(): void
    {
        $character = $this->character();
        $this->forceEggRoll();
        $ruins = app(NamelessRuinService::class);
        $uuid = (string) Str::uuid();
        $result = $ruins->fight($character, 'sand', 1, false, $uuid, 3);
        $this->assertSame('victory', $result['battle_result']);
        $this->assertSame(3, $result['batch_explore']['completed']);
        $this->assertNotNull($result['valmon_egg_found']);
        $this->assertSame($result, $ruins->fight($character, 'sand', 1, false, $uuid, 3));
        $this->assertSame(1, PlayerValmonEgg::count());
        $this->assertSame(1, PublicLog::where('type', 'valmon')->count());
        $this->assertSame(97, $character->fresh()->explore_stamina);
        $this->assertGreaterThan(0, $result['exp_gained']);
        $this->assertGreaterThan(0, $result['job_exp_gained']);
        $this->assertSame(3, (int) $character->fresh()->wins);
    }

    public function test_batch_defeat_preserves_earlier_egg_and_defeat_does_not_roll_again(): void
    {
        $character = $this->character();
        $this->forceEggRoll();
        $win = new BattleResult();
        $win->result = 'victory';
        $win->playerHpAfter = 1000;
        $loss = new BattleResult();
        $loss->result = 'defeat';
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->twice()->andReturn($win, $loss);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid(), 3);
        $this->assertSame('defeat', $result['battle_result']);
        $this->assertNotNull($result['valmon_egg_found']);
        $this->assertFalse(PlayerValmonEgg::firstOrFail()->is_lost);
        $this->assertSame(1, PlayerValmonEgg::count());
    }

    public function test_full_bag_and_failed_transaction_do_not_consume_stamina_or_leave_eggs(): void
    {
        $character = $this->character();
        $this->forceEggRoll();
        $character->update(['equipment_storage_limit' => 300]);
        \App\Models\PlayerNamelessEquipment::insert(array_fill(0, 300, ['character_id' => $character->id, 'kind' => 'weapon',
            'equipment_type' => '剣', 'base_power' => 5, 'power_per_level' => 5]));
        try {
            app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
            $this->fail('A full bag accepted a battle.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('所持枠', $exception->getMessage());
        }
        $this->assertSame(100, $character->fresh()->explore_stamina);
        $this->assertSame(0, PlayerValmonEgg::count());
        $character->update(['equipment_storage_limit' => 301]);
        $this->mock(\App\Services\PublicLogService::class)->shouldReceive('addLog')->once()->andThrow(new RuntimeException('log rollback'));
        try {
            app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
            $this->fail('The failed operation committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('log rollback', $exception->getMessage());
        }
        $this->assertSame(0, PlayerValmonEgg::count());
        $this->assertSame(100, $character->fresh()->explore_stamina);
    }

    public function test_result_is_read_only_and_native_return_hatches_once_even_when_ruins_are_disabled(): void
    {
        $character = $this->character();
        $this->forceEggRoll();
        $uuid = (string) Str::uuid();
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $uuid);
        $user = $character->user;
        $response = $this->actingAs($user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->get(route('nameless-workshop.result', $uuid))
            ->assertOk()->assertSee($result['valmon_egg_found']['name'].'の卵を見つけた！')
            ->assertSee('卵を連れて街へ戻る')->assertSee(route('battle.return'), false);
        $this->saveQaView('result', $response->getContent());
        $this->assertSame(0, PlayerValmon::count());
        config(['nameless_relics.enabled' => false]);
        $this->post(route('battle.return'))->assertRedirect()->assertSessionHas('message');
        $this->post(route('battle.return'))->assertRedirect();
        $this->assertSame(1, PlayerValmon::count());
        $this->assertTrue(PlayerValmonEgg::firstOrFail()->is_hatched);
        $this->assertSame(1, PublicLog::where('type', 'valmon')->where('message', 'like', '【ヴァルモン誕生】%')->count());
        $this->assertSame('gather', app(ValmonService::class)->role(PlayerValmon::with('master')->firstOrFail()));
        $response = $this->get(route('valmons.index'))->assertOk()->assertSee('ヒント：沈水の水路で卵が見つかることがあります。');
        $this->saveQaView('ranch', $response->getContent());
    }

    private function saveQaView(string $name, string $html): void
    {
        if (getenv('NAMELESS_VALMON_QA_OUTPUT') !== '1') {
            return;
        }
        $directory = base_path('scratch/nameless-valmons-implementation-20261008/ui');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $html);
    }

    public function test_adventurer_card_includes_new_rare_species_beyond_the_original_twenty_one_slots(): void
    {
        $character = $this->character();
        foreach (range(1, 21) as $no) {
            ValmonMaster::create(['valmon_key' => 'card_old_'.$no, 'name' => '旧種'.$no,
                'rarity' => 'normal', 'is_active' => true, 'sort_order' => $no]);
        }
        $master = ValmonMaster::where('valmon_key', 'ruin_noxia')->firstOrFail();
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $master->id,
            'is_partner' => true, 'obtained_at' => now()]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $component = \Livewire\Livewire::test(\App\Livewire\CityHeader::class, ['modalOnly' => true])
            ->call('openPlayerModal', $character->id);
        $badges = $component->get('playerInfo')['valmon_badges'];
        $this->assertCount(36, $badges); // All 21 existing and 15 new species are included.
        $rare = collect($badges)->firstWhere('species', 'ノクシア');
        $this->assertTrue($rare['owned']);
        $this->assertTrue($rare['is_partner']);
        $this->assertStringContainsString('valmon_036.webp', $rare['image']);
        $this->saveQaView('card', $component->html());
    }

    private function forceEggRoll(): ValmonService
    {
        $service = \Mockery::mock(ValmonService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('rollPercent')->andReturnUsing(function (float $percent): bool {
            $this->assertContains($percent, [0.0, 0.02]);
            return $percent > 0;
        });
        $service->shouldReceive('ruinEggTicket')->andReturn(1);
        $this->app->instance(ValmonService::class, $service);
        return $service;
    }

    private function candidate(Character $character, string $zone, ?int $ticket = null): ?ValmonMaster
    {
        return (new ReflectionMethod(ValmonService::class, 'ruinMasterForTicket'))
            ->invoke(app(ValmonService::class), $character, $zone, $ticket);
    }

    private function egg(Character $character, ValmonMaster $master, array $extra = []): PlayerValmonEgg
    {
        return PlayerValmonEgg::create($extra + ['character_id' => $character->id,
            'valmon_master_id' => $master->id, 'found_at' => now()->subDay()]);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '遺跡卵検証',
            'current_city_id' => $town->id, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(),
            'hp_base' => 1000000, 'current_hp' => 1000000, 'mp_base' => 1000, 'current_mp' => 500,
            'attack_base' => 1000000, 'magic_base' => 1000000, 'defense_base' => 100000,
            'spirit_base' => 100000, 'speed_base' => 1000000, 'luck_base' => 1000, 'money' => 10000]);
    }
}
