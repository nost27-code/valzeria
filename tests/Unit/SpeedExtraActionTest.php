<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\SpeedExtraActionService;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2TimedEffectState;
use App\Services\PvPBattleService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SpeedExtraActionTest extends TestCase
{
    #[DataProvider('chanceProvider')]
    public function test_extra_action_chance_follows_the_speed_ratio(
        int $ownSpeed,
        int $opponentSpeed,
        float $expected,
    ): void {
        $chance = (new SpeedExtraActionService)->calculateChance(
            $this->actor('自分', $ownSpeed),
            $this->actor('相手', $opponentSpeed),
        );

        $this->assertSame($expected, $chance);
    }

    public static function chanceProvider(): array
    {
        return [
            '同値は追加行動なし' => [1000, 1000, 0.0],
            '下回る場合も追加行動なし' => [500, 1000, 0.0],
            '1.25倍で12.5%' => [1250, 1000, 12.5],
            '1.5倍で25%' => [1500, 1000, 25.0],
            '2倍で50%' => [2000, 1000, 50.0],
            '2.5倍で75%' => [2500, 1000, 75.0],
            '3倍で100%' => [3000, 1000, 100.0],
            '3倍を超えても100%で頭打ち' => [10000, 1000, 100.0],
        ];
    }

    #[DataProvider('pityProvider')]
    public function test_pity_threshold_is_derived_from_the_chance(float $chance, int $expected): void
    {
        $this->assertSame($expected, (new SpeedExtraActionService)->pityThreshold($chance));
    }

    public static function pityProvider(): array
    {
        return [
            [12.5, 16],
            [25.0, 8],
            [50.0, 4],
            [75.0, 3],
        ];
    }

    public function test_zero_chance_never_grants_and_never_accumulates_pity(): void
    {
        $service = new SpeedExtraActionService;
        $actor = $this->actor('自分', 1000);
        $opponent = $this->actor('相手', 1000);

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($service->shouldGrantExtraAction($actor, $opponent));
        }

        $this->assertSame(0, $actor->extraActionMissCount);
    }

    public function test_full_chance_always_grants_without_rolling(): void
    {
        $service = new SpeedExtraActionService;
        $actor = $this->actor('自分', 3000);
        $opponent = $this->actor('相手', 1000);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($service->shouldGrantExtraAction($actor, $opponent));
            $this->assertSame(0, $actor->extraActionMissCount);
        }
    }

    public function test_pity_guarantees_an_extra_action_after_the_configured_miss_streak(): void
    {
        $service = new SpeedExtraActionService;
        $actor = $this->actor('自分', 1500);
        $opponent = $this->actor('相手', 1000);
        $actor->extraActionMissCount = 8;

        $this->assertTrue($service->shouldGrantExtraAction($actor, $opponent));
        $this->assertSame(0, $actor->extraActionMissCount, '保証発動でカウンタは0に戻る。');
    }

    public function test_pity_counter_never_exceeds_the_threshold_over_a_long_battle(): void
    {
        $service = new SpeedExtraActionService;
        $actor = $this->actor('自分', 1250);
        $opponent = $this->actor('相手', 1000);
        $threshold = $service->pityThreshold(12.5);
        $grants = 0;

        for ($round = 0; $round < 200; $round++) {
            if ($service->shouldGrantExtraAction($actor, $opponent)) {
                $grants++;
                $this->assertSame(0, $actor->extraActionMissCount);
            }

            $this->assertLessThanOrEqual($threshold, $actor->extraActionMissCount);
        }

        $this->assertGreaterThan(10, $grants);
    }

    public function test_extra_action_runs_once_per_round_without_advancing_the_turn(): void
    {
        $service = $this->recordingPvpService();
        $fast = $this->actor('速いほう', 3000);
        $slow = $this->actor('遅いほう', 1000);
        $state = new BattleState($fast, $slow, 'pvp');
        $state->turnCount = 7;

        $this->invokeResolve($service, $state, $fast, $slow);

        $this->assertCount(1, $service->calls, '追加行動は1ラウンド1回まで。');
        $this->assertSame('速いほう', $service->calls[0]['actor']);
        $this->assertFalse($service->calls[0]['tickCooldowns']);
        $this->assertSame(7, $state->turnCount, '追加行動でturnCountは増えない。');
    }

    public function test_slower_side_never_receives_an_extra_action(): void
    {
        $service = $this->recordingPvpService();
        $first = $this->actor('同速1', 1000);
        $second = $this->actor('同速2', 1000);
        $state = new BattleState($first, $second, 'pvp');

        $this->invokeResolve($service, $state, $first, $second);

        $this->assertSame([], $service->calls);
    }

    public function test_no_extra_action_when_either_side_is_already_dead(): void
    {
        $service = $this->recordingPvpService();
        $fast = $this->actor('速いほう', 3000);
        $slow = $this->actor('遅いほう', 1000);
        $slow->hp = 0;
        $state = new BattleState($fast, $slow, 'pvp');

        $this->invokeResolve($service, $state, $fast, $slow);

        $this->assertSame([], $service->calls);
    }

    public function test_extra_action_never_ends_the_round_or_chains_into_itself(): void
    {
        foreach ([PvPBattleService::class, ChampBattleService::class] as $route) {
            $source = $this->methodSource($route, 'resolveSpeedExtraAction');

            $this->assertStringNotContainsString('endRound', $source, $route);
            $this->assertStringNotContainsString('turnCount', $source, $route);
            $this->assertStringContainsString('return', $source, $route);
        }
    }

    public function test_both_competitive_routes_share_the_same_extra_action_service(): void
    {
        foreach ([PvPBattleService::class, ChampBattleService::class] as $route) {
            $source = file_get_contents((new ReflectionClass($route))->getFileName());

            $this->assertStringContainsString('SpeedExtraActionService', $source, $route);
            $this->assertStringContainsString('shouldGrantExtraAction(', $source, $route);
        }
    }

    public function test_extra_action_reuses_the_normal_action_path(): void
    {
        $this->assertStringContainsString(
            '$this->executeActionWithRoomRule($actor, $opponent, $state, false)',
            $this->methodSource(PvPBattleService::class, 'resolveSpeedExtraAction'),
        );

        $this->assertStringContainsString(
            '$performAction($actor, $target)',
            $this->methodSource(ChampBattleService::class, 'runBattle'),
        );
        $this->assertStringContainsString(
            '$performAction($actor, $target, false)',
            $this->methodSource(ChampBattleService::class, 'resolveSpeedExtraAction'),
        );
    }

    public function test_round_decay_never_runs_twice_inside_one_round(): void
    {
        $effect = new JobArtV2TimedEffectState(
            key: 'extra_action_round_guard',
            statModifiers: ['agi' => 0.10],
            appliedRound: 3,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 1.0,
        );

        $this->assertFalse($effect->advanceAtRoundEnd(3));
        $this->assertSame(2, $effect->remainingRounds);
        $this->assertTrue($effect->advanceAtRoundEnd(4));
        $this->assertSame(1, $effect->remainingRounds);
        $this->assertFalse($effect->advanceAtRoundEnd(4));
        $this->assertSame(1, $effect->remainingRounds);
    }

    public function test_champ_route_skips_the_cooldown_tick_on_the_extra_action(): void
    {
        $this->assertStringContainsString(
            'if ($tickCooldowns) {',
            $this->methodSource(ChampBattleService::class, 'champAction'),
        );
        $this->assertStringContainsString(
            '$performAction($actor, $target, false)',
            $this->methodSource(ChampBattleService::class, 'resolveSpeedExtraAction'),
        );
    }

    public function test_champ_extra_action_executes_once_through_the_shared_action_closure(): void
    {
        $service = app(ChampBattleService::class);
        $fast = $this->actor('速いほう', 3_000);
        $slow = $this->actor('遅いほう', 1_000);
        $state = new BattleState($fast, $slow, 'champ');
        $log = [];
        $calls = [];
        $performAction = static function (
            BattleActor $actor,
            BattleActor $target,
            bool $tickCooldowns = true,
        ) use (&$calls): bool {
            $calls[] = [
                'actor' => $actor->name,
                'target' => $target->name,
                'tickCooldowns' => $tickCooldowns,
            ];

            return false;
        };

        $method = new ReflectionMethod(ChampBattleService::class, 'resolveSpeedExtraAction');
        $method->setAccessible(true);
        $battleEnded = $method->invokeArgs($service, [
            $state,
            $fast,
            $slow,
            &$log,
            $performAction,
        ]);

        $this->assertFalse($battleEnded);
        $this->assertSame([[
            'actor' => '速いほう',
            'target' => '遅いほう',
            'tickCooldowns' => false,
        ]], $calls);
        $this->assertCount(1, $log);
        $this->assertStringContainsString('【神速】', $log[0]);
        $this->assertSame(0, $state->turnCount);
    }

    private function actor(string $name, int $speed): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => $speed,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }

    private function recordingPvpService(): PvPBattleService
    {
        return new class(app(CharacterStatusService::class), app(DamageCalculator::class), app(JobArtBattleSupportService::class)) extends PvPBattleService
        {
            /** @var list<array{actor:string, tickCooldowns:bool}> */
            public array $calls = [];

            protected function executeActionWithRoomRule(
                BattleActor $actor,
                BattleActor $opponent,
                BattleState $state,
                bool $tickCooldowns = true,
            ): void {
                $this->calls[] = ['actor' => $actor->name, 'tickCooldowns' => $tickCooldowns];
            }
        };
    }

    private function invokeResolve(
        PvPBattleService $service,
        BattleState $state,
        BattleActor $attacker,
        BattleActor $defender,
    ): void {
        $method = new ReflectionMethod(PvPBattleService::class, 'resolveSpeedExtraAction');
        $method->setAccessible(true);
        $method->invoke($service, $state, $attacker, $defender);
    }

    private function methodSource(string $class, string $methodName): string
    {
        $method = new ReflectionMethod($class, $methodName);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
