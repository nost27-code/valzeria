<?php

namespace Tests\Unit;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\Battle\SixHeroRoomRuleResolver;
use App\Services\Battle\SpeedBreakthroughService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\PvPBattleService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SpeedBreakthroughServiceTest extends TestCase
{
    #[DataProvider('nominalRateCases')]
    public function test_speed_breakthrough_nominal_rate_boundaries(float $ratio, float $expected): void
    {
        $actual = $this->service()->nominalRateForAgility($ratio * 10_000, 10_000);

        $this->assertEqualsWithDelta($expected, $actual, 0.000000001, "ratio={$ratio}");
    }

    /** @return iterable<string, array{float, float}> */
    public static function nominalRateCases(): iterable
    {
        yield 'R 1.00' => [1.00, 0.0];
        yield 'R 1.30 exact remains zero' => [1.30, 0.0];
        yield 'R 1.3001 starts above the dead zone' => [1.3001, 0.000125];
        yield 'R 1.40' => [1.40, 0.125];
        yield 'R 1.50' => [1.50, 0.25];
        yield 'R 1.54 reaches the cap' => [1.54, 0.30];
        yield 'R 1.55 stays capped' => [1.55, 0.30];
        yield 'R 2.00 stays capped' => [2.00, 0.30];
        yield 'R 3.00 stays capped' => [3.00, 0.30];
    }

    public function test_speed_breakthrough_is_zero_at_or_below_equal_agility_and_safe_for_zero_defender_agility(): void
    {
        $service = $this->service();

        $this->assertSame(0.0, $service->nominalRateForAgility(100, 100));
        $this->assertSame(0.0, $service->nominalRateForAgility(99, 100));
        $this->assertSame(0.30, $service->nominalRateForAgility(100, 0));
    }

    #[DataProvider('ignoreCompositionCases')]
    public function test_speed_breakthrough_adds_only_the_required_post_existing_ignore_reduction(
        float $existing,
        float $nominal,
        float $expectedCombined,
        float $expectedAdditional,
        float $expectedDefenseFactor,
    ): void {
        $rates = $this->service()->rates($nominal, $existing);
        $actualDefenseFactor = (1 - $existing) * (1 - $rates['additional_ignore_rate']);

        $this->assertEqualsWithDelta($expectedCombined, $rates['combined_ignore_rate'], 0.000000001);
        $this->assertEqualsWithDelta($expectedAdditional, $rates['additional_ignore_rate'], 0.000000001);
        $this->assertEqualsWithDelta($expectedDefenseFactor, $actualDefenseFactor, 0.000000001);
    }

    /** @return iterable<string, array{float, float, float, float, float}> */
    public static function ignoreCompositionCases(): iterable
    {
        yield 'E0 S30' => [0.0, 0.30, 0.30, 0.30, 0.70];
        yield 'E20 S30' => [0.20, 0.30, 0.44, 0.30, 0.56];
        yield 'E50 S30' => [0.50, 0.30, 0.50, 0.0, 0.50];
        yield 'E20 S0' => [0.20, 0.0, 0.20, 0.0, 0.80];
    }

    public function test_speed_breakthrough_uses_effective_agility_after_a_slow_effect(): void
    {
        $attacker = $this->actor('敏捷側', 150);
        $defender = $this->actor('相手', 100);

        $this->assertEqualsWithDelta(0.25, $this->service()->nominalRate($attacker, $defender), 0.000000001);

        $attacker->conditions['slow'] = ['rate' => 0.25];

        $this->assertSame(112, $attacker->effectiveAgi());
        $this->assertSame(0.0, $this->service()->nominalRate($attacker, $defender));
    }

    public function test_speed_breakthrough_snapshot_is_stable_within_one_action_and_refreshes_for_the_next_action(): void
    {
        $attacker = $this->actor('敏捷側', 154);
        $defender = $this->actor('相手', 100);
        $state = new BattleState($attacker, $defender, 'pvp');
        $state->speedBreakthroughEnabled = true;
        $state->beginCompetitiveAction($attacker, $defender);
        $state->snapshotSpeedBreakthrough($attacker, $defender, $this->service()->nominalRate($attacker, $defender));

        $firstHit = $state->speedBreakthroughNominalRate($attacker, $defender);
        $attacker->agi = 100;
        $secondHit = $state->speedBreakthroughNominalRate($attacker, $defender);

        $this->assertSame(0.30, $firstHit);
        $this->assertSame($firstHit, $secondHit);

        $state->beginCompetitiveAction($attacker, $defender);
        $state->snapshotSpeedBreakthrough($attacker, $defender, $this->service()->nominalRate($attacker, $defender));

        $this->assertSame(0.0, $state->speedBreakthroughNominalRate($attacker, $defender));
    }

    public function test_speed_breakthrough_context_is_disabled_for_arena_and_training_and_enabled_for_all_six_hero_factories(): void
    {
        $factory = new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver());
        $room = SixHeroRoomKey::SEAL_MAGIC;

        $this->assertFalse(PvPBattleExecutionContext::arena()->speedBreakthroughEnabled);
        $this->assertFalse(PvPBattleExecutionContext::trainingGround()->speedBreakthroughEnabled);
        $this->assertTrue($factory->make($room)->speedBreakthroughEnabled);
        $this->assertTrue($factory->makeOfficial($room)->speedBreakthroughEnabled);
        $this->assertTrue($factory->makePractice($room)->speedBreakthroughEnabled);
    }

    public function test_speed_breakthrough_effective_condition_requires_both_context_and_config_flags(): void
    {
        $this->assertFalse($this->resolvedSpeedBreakthroughFlag(false, true));
        $this->assertFalse($this->resolvedSpeedBreakthroughFlag(true, false));
        $this->assertTrue($this->resolvedSpeedBreakthroughFlag(true, true));
    }

    public function test_speed_breakthrough_flag_off_keeps_the_fixed_rank_damage_result_identical(): void
    {
        config(['battle.speed_breakthrough.enabled' => false]);
        $attacker = $this->actor('攻撃側', 154, str: 1_000);
        $defender = $this->actor('防御側', 100, hp: 5_000, def: 600, spr: 400);
        $calculator = new DamageCalculator();

        mt_srand(250_825);
        $withoutBreakthroughArgument = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');
        mt_srand(250_825);
        $explicitZeroBreakthrough = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            additionalDefenseIgnoreRate: 0.0,
        );

        $this->assertSame($withoutBreakthroughArgument, $explicitZeroBreakthrough);
    }

    private function service(): SpeedBreakthroughService
    {
        return new SpeedBreakthroughService();
    }

    private function actor(
        string $name,
        int $agi,
        int $str = 100,
        int $hp = 1_000,
        int $def = 100,
        int $spr = 100,
    ): BattleActor {
        return new BattleActor($name, true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 100,
            'max_mp' => 100,
            'str' => $str,
            'def' => $def,
            'agi' => $agi,
            'mag' => 100,
            'spr' => $spr,
            'luk' => 100,
        ]);
    }

    private function resolvedSpeedBreakthroughFlag(bool $configEnabled, bool $contextEnabled): bool
    {
        config(['battle.speed_breakthrough.enabled' => $configEnabled]);
        $stats = [
            'max_hp' => 100,
            'max_mp' => 10,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ];
        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->twice()->andReturn($stats);
        $support = Mockery::mock(JobArtBattleSupportService::class);
        $support->shouldIgnoreMissing();
        $support->shouldReceive('usesRoleEffects')->andReturn(false);
        $service = new class($statusService, new DamageCalculator(), $support) extends PvPBattleService
        {
            public ?bool $resolvedSpeedBreakthroughEnabled = null;

            protected function executeAction(
                BattleActor $attacker,
                BattleActor $defender,
                BattleState $state,
                bool $tickCooldowns = true,
            ): void {
                $this->resolvedSpeedBreakthroughEnabled = $state->speedBreakthroughEnabled;
                $defender->takeDamage($defender->hp);
            }
        };
        $attacker = new Character(['name' => 'A']);
        $attacker->setRelation('currentJob', null);
        $defender = new Character(['name' => 'B']);
        $defender->setRelation('currentJob', null);

        $service->resolveBattle(
            $attacker,
            $defender,
            new PvPBattleExecutionContext(speedBreakthroughEnabled: $contextEnabled),
        );

        return $service->resolvedSpeedBreakthroughEnabled ?? false;
    }
}
