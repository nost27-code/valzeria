<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\Admin\SixHeroBattleSimulator;
use App\Models\ArenaLog;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\User;
use App\Services\Admin\SixHeroBattleSimulatorService;
use App\Services\Battle\BattleResult;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\Battle\SixHeroRoomRuleResolver;
use App\Services\PvPBattleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class AdminSixHeroBattleSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_battle_simulator_page_includes_the_six_hero_lab(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.battle-simulator'))
            ->assertOk()
            ->assertSee('六英雄戦シミュレーション')
            ->assertSee('現在適用されるランク戦ダメージ式')
            ->assertSee('順位・挑戦回数・戦績・HP/SP・戦闘ログDBは更新しません')
            ->assertSee('攻撃能力×0.56', escape: false)
            ->assertSee('通常攻撃の表示威力は125%')
            ->assertSee('通常戦闘シミュレーション');
    }

    public function test_non_admin_cannot_boot_the_six_hero_lab_component(): void
    {
        $player = User::factory()->create(['role' => 'user']);

        Livewire::actingAs($player)
            ->test(SixHeroBattleSimulator::class)
            ->assertForbidden();
    }

    public function test_admin_can_run_repeated_room_simulations_and_see_aggregate_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $attacker = $this->character('検証用の挑戦者');
        $defender = $this->character('検証用の防衛者');
        $contexts = [];
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldReceive('resolveBattle')
            ->twice()
            ->andReturnUsing(function (
                Character $actualAttacker,
                Character $actualDefender,
                PvPBattleExecutionContext $context,
            ) use (&$contexts, $attacker, $defender): PvPBattleResolution {
                $this->assertTrue($actualAttacker->is($attacker));
                $this->assertTrue($actualDefender->is($defender));
                $contexts[] = $context;

                return $this->resolution(count($contexts) === 1);
            });
        $this->app->instance(PvPBattleService::class, $pvpBattleService);

        Livewire::actingAs($admin)
            ->test(SixHeroBattleSimulator::class)
            ->call('selectAttacker', $attacker->id)
            ->call('selectDefender', $defender->id)
            ->call('selectRoom', SixHeroRoomKey::MIRACLE->value)
            ->set('simulationCount', 2)
            ->call('runSimulation')
            ->assertHasNoErrors()
            ->assertSet('summary.total', 2)
            ->assertSet('summary.attacker_wins', 1)
            ->assertSet('summary.defender_wins', 1)
            ->assertSet('summary.attacker_win_rate', 50.0)
            ->assertSee('サンプル1戦の全ログ')
            ->assertSee('検証ダメージ 321');

        $this->assertCount(2, $contexts);
        foreach ($contexts as $context) {
            $this->assertSame('奇跡の間', $context->displayLabel);
            $this->assertSame('champ', $context->jobArtContext);
            $this->assertFalse($context->rankBattleMinimumDamageGuaranteeEnabled);
            $this->assertFalse($context->rankBattleDamageCapEnabled);
        }
        $this->assertNotSame($contexts[0]->roomRule, $contexts[1]->roomRule);
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertDatabaseCount('arena_logs', 0);
    }

    public function test_every_room_uses_a_fresh_official_equivalent_rule_without_registration(): void
    {
        $attacker = $this->character('未登録の挑戦者');
        $defender = $this->character('未登録の防衛者');
        $contexts = [];
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldReceive('resolveBattle')
            ->times(count(SixHeroRoomKey::cases()))
            ->andReturnUsing(function (
                Character $actualAttacker,
                Character $actualDefender,
                PvPBattleExecutionContext $context,
            ) use (&$contexts): PvPBattleResolution {
                $contexts[] = $context;

                return $this->resolution(true);
            });
        $resolver = new SixHeroRoomRuleResolver;
        $service = new SixHeroBattleSimulatorService(
            $pvpBattleService,
            new SixHeroBattleContextFactory($resolver),
        );

        foreach (SixHeroRoomKey::cases() as $room) {
            $result = $service->simulate($room, $attacker, $defender);
            $this->assertSame($room, $result->room);
        }

        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $this->assertSame(
                $resolver->resolve($room)::class,
                $contexts[$index]->roomRule::class,
            );
        }
        $this->assertCount(
            count($contexts),
            collect($contexts)
                ->map(static fn (PvPBattleExecutionContext $context): int => spl_object_id($context->roomRule))
                ->unique(),
        );
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertSame(0, ArenaLog::query()->count());
        $this->assertSame(0, SixHeroRanking::query()->count());
        $this->assertSame(0, SixHeroDailyUsage::query()->count());
        $this->assertSame(0, SixHeroBattleLog::query()->count());
    }

    public function test_same_character_is_rejected_before_the_battle_engine_runs(): void
    {
        $character = $this->character('同一人物');
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('resolveBattle');
        $service = new SixHeroBattleSimulatorService(
            $pvpBattleService,
            new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver),
        );

        $this->expectException(DomainException::class);

        $service->simulate(
            SixHeroRoomKey::SEAL_BLADE,
            $character,
            $character,
        );
    }

    public function test_real_current_battle_engine_resolves_without_persisting_character_or_competitive_state(): void
    {
        $attacker = $this->character('実計算の挑戦者');
        $defender = $this->character('実計算の防衛者');
        $attacker->forceFill([
            'hp_base' => 180,
            'attack_base' => 160,
            'defense_base' => 20,
            'speed_base' => 30,
            'magic_base' => 10,
            'luck_base' => 8,
            'current_hp' => 73,
            'current_mp' => 19,
        ])->save();
        $defender->forceFill([
            'hp_base' => 180,
            'attack_base' => 150,
            'defense_base' => 20,
            'speed_base' => 25,
            'magic_base' => 10,
            'luck_base' => 8,
            'current_hp' => 91,
            'current_mp' => 17,
        ])->save();

        $result = $this->app
            ->make(SixHeroBattleSimulatorService::class)
            ->simulate(SixHeroRoomKey::DIVINE_SPEED, $attacker, $defender);

        $this->assertSame(SixHeroRoomKey::DIVINE_SPEED, $result->room);
        $this->assertGreaterThan(0, $result->resolution->turnCount);
        $this->assertNotSame([], $result->resolution->result->logs);
        $this->assertStringContainsString(
            '【神速の間】',
            implode("\n", $result->resolution->result->logs),
        );
        $this->assertSame(73, $attacker->fresh()->current_hp);
        $this->assertSame(19, $attacker->fresh()->current_mp);
        $this->assertSame(91, $defender->fresh()->current_hp);
        $this->assertSame(17, $defender->fresh()->current_mp);
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertDatabaseCount('arena_logs', 0);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    private function resolution(bool $attackerWon): PvPBattleResolution
    {
        $result = new BattleResult;
        $result->result = $attackerWon ? 'victory' : 'defeat';
        $result->logs = ['検証ダメージ 321'];
        $result->playerMpAfter = 80;

        return new PvPBattleResolution(
            result: $result,
            attackerWon: $attackerWon,
            turnCount: $attackerWon ? 7 : 11,
            attackerHp: $attackerWon ? 800 : 0,
            attackerMaxHp: 1000,
            defenderHp: $attackerWon ? 0 : 600,
            defenderMaxHp: 1200,
        );
    }
}
