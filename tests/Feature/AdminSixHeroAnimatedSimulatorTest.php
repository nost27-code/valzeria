<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\Admin\SixHeroBattleSimulator;
use App\Models\Character;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\Admin\SixHeroBattleSimulatorService;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Services\PvPBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../Support/SixHeroReplayRandom.php';

final class AdminSixHeroAnimatedSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_room_records_actual_actions_logs_and_terminal_vitals_without_mutation(): void
    {
        $a = $this->character('挑戦者');
        $b = $this->character('防衛者');
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $service = app(SixHeroBattleSimulatorService::class);

        foreach (SixHeroRoomKey::cases() as $room) {
            $result = $service->simulate($room, $a, $b, recordReplay: true)->resolution;
            $replay = $result->result->battleReplay;
            $this->assertSame(1, $replay['version']);
            $frames = $replay['frames'];
            $last = $frames[array_key_last($frames)]['actors'];
            $this->assertSame($result->attackerHp, $last['player']['hp']);
            $this->assertSame($result->defenderHp, $last['enemy']['hp']);
            $this->assertSame($result->result->playerMpAfter, $last['player']['sp']);
            $this->assertSame($result->result->logs, array_merge(...array_column($frames, 'logs')));
            $this->assertNotEmpty(array_filter($frames, fn ($frame) => ! empty($frame['action']['side'])));
            $this->assertNotEmpty(array_merge(...array_map(fn ($frame) => $frame['action']['hits'] ?? [], $frames)));
            $this->assertLessThan(1024 * 1024, strlen(json_encode($replay)));
        }

        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        $this->assertNoGameWrites();
    }

    public function test_recording_on_and_off_keeps_the_same_resolution_with_controlled_random_damage(): void
    {
        $a = $this->character('同一計算A');
        $b = $this->character('同一計算B');
        // Keep cryptographic damage/hit RNG fixed; seeded rand still exercises initiative.
        $calculator = Mockery::mock(DamageCalculator::class)->makePartial();
        $calculator->shouldReceive('isHit')->andReturn(true);
        $calculator->shouldReceive('isRankBattleCritical')->andReturn(false);
        $calculator->shouldReceive('calculateRankBattleDamage')->andReturn(20);
        $this->app->instance(DamageCalculator::class, $calculator);
        $service = app(SixHeroBattleSimulatorService::class);

        foreach (SixHeroRoomKey::cases() as $room) {
            $GLOBALS['sixHeroReplayRng'] = new \Random\Randomizer(new \Random\Engine\Mt19937(9281));
            $off = \App\Services\Battle\ScopedBattleRandomizer::run($GLOBALS['sixHeroReplayRng'], fn () => $service->simulate($room, $a, $b)->resolution);
            $GLOBALS['sixHeroReplayRng'] = new \Random\Randomizer(new \Random\Engine\Mt19937(9281));
            $on = \App\Services\Battle\ScopedBattleRandomizer::run($GLOBALS['sixHeroReplayRng'], fn () => $service->simulate($room, $a, $b, recordReplay: true)->resolution);
            unset($GLOBALS['sixHeroReplayRng']);
            $this->assertNotNull($on->result->battleReplay);
            $on->result->battleReplay = null;
            $this->assertEquals($off, $on);
        }
    }

    public function test_skill_multi_hits_hp_and_sp_recovery_are_observed_from_the_engine(): void
    {
        $a = new \App\Services\Battle\BattleActor('戦技側', true, ['hp' => 300, 'max_hp' => 1000, 'mp' => 10, 'max_mp' => 100, 'str' => 100, 'def' => 50, 'agi' => 10, 'mag' => 100, 'spr' => 50, 'luk' => 10]);
        $b = new \App\Services\Battle\BattleActor('相手', false, ['hp' => 1000, 'max_hp' => 1000, 'str' => 100, 'def' => 50, 'agi' => 10, 'mag' => 100, 'spr' => 50, 'luk' => 10]);
        $state = new \App\Services\Battle\BattleState($a, $b, 'pvp');
        $state->replay = new \App\Services\Battle\BattleReplayRecorder($state);
        $skill = new \App\Models\Skill([
            'name' => '検証用の三連回復技', 'skill_type' => 'special', 'learn_rank' => 1,
            'damage_type' => 'physical', 'power_multiplier' => 1.5, 'hit_count' => 3,
            'heal_percent' => 20, 'mp_recover_percent' => 20,
        ]);
        $state->replay->begin($a);
        (new \ReflectionMethod(PvPBattleService::class, 'executeSkillAction'))
            ->invoke(app(PvPBattleService::class), $a, $b, $state, $skill);
        $state->replay->finish();
        $frame = $state->replay->result()['frames'][0];
        $this->assertSame($skill->name, $frame['action']['name']);
        $this->assertCount(3, $frame['action']['hits']);
        $this->assertSame(1000 - $b->hp, array_sum(array_column($frame['action']['hits'], 'damage')));
        $this->assertGreaterThan(300, $frame['actors']['player']['hp']);
        $this->assertSame($a->hp, $frame['actors']['player']['hp']);
        $this->assertSame(30, $frame['actors']['player']['sp']);
        $this->assertSame($state->logs, $frame['logs']);
    }

    public function test_start_rereads_latest_stats_and_opens_the_shared_battle_screen(): void
    {
        config(['battle.replay.enabled' => false]);
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->character('最新能力A');
        $b = $this->character('最新能力B');
        $component = Livewire::actingAs($admin)->test(SixHeroBattleSimulator::class)
            ->call('selectAttacker', $a->id)->call('selectDefender', $b->id);
        app(CharacterStatusService::class)->getFinalStats($a);
        $a->update(['attack_base' => 777, 'current_hp' => 13, 'current_mp' => 7]);
        $before = $a->fresh()->getAttributes();
        $component->call('playBattle')->assertHasNoErrors()->assertRedirect();
        $url = $component->effects['redirect'];
        $token = basename(parse_url($url, PHP_URL_PATH));
        $preview = Cache::get('six_hero_simulator:'.$admin->id.':'.$token);
        $attack = collect($preview['battleResult']['attackerCombatant']['stats'])->firstWhere('label', '攻撃');
        $this->assertSame(777, $attack['value']);
        $this->assertSame($preview['battleReplay']['initial']['player']['maxHp'], $preview['battleReplay']['initial']['player']['hp']);
        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertSame(['attacker' => $a->id, 'defender' => $b->id, 'room' => 'seal_magic'], session('six_hero_simulator_selection'));

        $response = $this->actingAs($admin)->get($url)->assertOk()
            ->assertViewIs('six-heroes.battle-result')->assertHeader('Cache-Control')->assertSee('data-battle-replay', false)
            ->assertSee('data-combatant-stats', false)->assertSee('取得元 testing')
            ->assertSee('シミュレーターへ戻る')->assertSee('再生')->assertSee('前へ');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        if ($directory = getenv('SIX_HERO_SIMULATOR_QA_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/battle.html', str_replace('http://localhost', 'http://ffa.test', $response->getContent()));
            file_put_contents($directory.'/selection.html', str_replace('http://localhost', 'http://ffa.test', $this->get(route('admin.battle-simulator'))->getContent()));
        }
        $this->assertNoGameWrites();
        Livewire::test(SixHeroBattleSimulator::class)->assertSet('selectedAttackerId', $a->id);
    }

    public function test_preview_is_private_to_the_creating_admin_and_expires(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create();
        $token = (string) Str::uuid();
        $url = route('admin.six-hero-simulator.result', ['token' => $token]);
        Cache::put('six_hero_simulator:'.$admin->id.':'.$token, ['private' => true], now()->addMinutes(30));
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($player)->get($url)->assertRedirect();
        $this->travel(31)->minutes();
        $this->actingAs($admin)->get($url)->assertNotFound();
    }

    public function test_current_room_top_ten_are_read_without_creating_or_changing_seasons(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $season = SixHeroSeason::create(['season_key' => now()->format('Y-m'), 'starts_at' => now()->startOfMonth(), 'ends_at' => now()->addMonth()->startOfMonth()]);
        foreach (range(1, 11) as $rank) {
            SixHeroRanking::create(['season_id' => $season->id, 'room_key' => 'divine_speed', 'character_id' => $this->character('上位候補'.$rank)->id, 'rank' => $rank, 'registered_at' => now()]);
        }
        Livewire::actingAs($admin)->test(SixHeroBattleSimulator::class)
            ->call('selectRoom', 'divine_speed')->assertSee('今期の上位10人')
            ->assertViewHas('topRankings', fn ($rankings) => $rankings->count() === 10 && $rankings->last()->rank === 10)
            ->call('selectRoom', 'miracle')->assertViewHas('topRankings', fn ($rankings) => $rankings->isEmpty());
        $this->assertDatabaseCount('six_hero_seasons', 1);
        $this->assertDatabaseCount('six_hero_rankings', 11);
    }

    public function test_same_actor_is_rejected_and_engine_failure_rolls_back_incidental_writes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->character('保護確認A');
        $b = $this->character('保護確認B');
        Livewire::actingAs($admin)->test(SixHeroBattleSimulator::class)
            ->set('selectedAttackerId', $a->id)->set('selectedDefenderId', $a->id)
            ->call('playBattle')->assertHasErrors(['selectedDefenderId']);
        $before = $a->fresh()->getAttributes();
        $level = DB::transactionLevel();
        $engine = Mockery::mock(PvPBattleService::class);
        $engine->shouldReceive('resolveBattle')->once()->andReturnUsing(function (Character $actor) {
            $actor->update(['current_hp' => 1]);
            throw new RuntimeException('Deliberate failure');
        });
        $this->app->instance(PvPBattleService::class, $engine);
        try {
            app(SixHeroBattleSimulatorService::class)->preview(SixHeroRoomKey::MIRACLE, $a->id, $b->id);
            $this->fail('Expected the failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate failure', $exception->getMessage());
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertNoGameWrites();
    }

    private function character(string $name): Character
    {
        return Character::create([
            'user_id' => User::factory()->create()->id, 'name' => $name,
            'hp_base' => 1000, 'mp_base' => 40, 'attack_base' => 100, 'defense_base' => 50,
            'magic_base' => 100, 'spirit_base' => 50, 'speed_base' => 10, 'luck_base' => 10,
            'current_hp' => 23, 'current_mp' => 11,
        ]);
    }

    private function assertNoGameWrites(): void
    {
        foreach (['six_hero_seasons', 'six_hero_rankings', 'six_hero_daily_usages', 'six_hero_battle_logs', 'arena_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
