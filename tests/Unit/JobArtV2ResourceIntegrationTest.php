<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Mockery;
use Tests\TestCase;

class JobArtV2ResourceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.normalized_sp' => false,
        ]);
    }

    public function test_producer_at_cap_is_skipped_and_the_back_slot_is_selected(): void
    {
        $producer = $this->art(24, 1, 100);
        $back = $this->art(10, 1, 100);
        [$actor, $state] = $this->battle(24, [$producer, $back]);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $result = $this->selection([1])->selectForTurn($actor, $state);

        $this->assertSame($back->id, $result->skill?->id);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_CAP, $result->blockedReasons[(int) $producer->id]);
    }

    public function test_rank_nine_is_prioritized_at_twelve_and_failed_activation_neither_retries_nor_spends(): void
    {
        $front = $this->art(10, 1, 100);
        $finisher = $this->art(53, 9, 50);
        [$actor, $state] = $this->battle(53, [$front, $finisher]);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $result = $this->selection([51, 1])->selectForTurn($actor, $state);

        $this->assertNull($result->skill);
        $this->assertSame($finisher->id, $result->candidateSkillId);
        $this->assertTrue($result->rankNinePrioritized);
        $this->assertFalse($result->retriedAfterMiss);
        $this->assertSame(12, $actor->getResource('star_mark'));
    }

    public function test_rank_nine_success_spends_only_after_existing_execution_state_is_finalized(): void
    {
        $finisher = $this->art(62, 9, 100);
        [$actor, $state] = $this->battle(62, [$finisher]);
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 12);
        $state->beginSourceAction();
        $support = $this->support($this->selection([1]));

        $selected = $support->selectForTurn($actor, $state);
        $this->assertSame($finisher, $selected);
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $selected));

        $this->assertSame(99, $actor->mp);
        $this->assertSame(1, array_sum($state->jobArtUseCounts));
        $this->assertSame(0, $actor->getResource('dragon_force'));
    }

    public function test_priest_rank_nine_remains_eligible_at_full_hp_when_barrier_can_be_applied(): void
    {
        $finisher = $this->art(24, 9, 100);
        $finisher->effect_template = 'HEAL_CLEANSE';
        $finisher->damage_reduction_percent = 20;
        [$actor, $state] = $this->battle(24, [$finisher]);
        $actor->hp = $actor->maxHp;
        $actor->conditions = [];
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $result = $this->selection([1])->selectForTurn($actor, $state);

        $this->assertSame($finisher->id, $result->skill?->id);
        $this->assertTrue($result->rankNinePrioritized);
    }

    public function test_final_resource_recheck_fails_without_mutating_sp_cooldown_use_count_or_resource(): void
    {
        $consumer = $this->art(24, 5, 100);
        $consumer->cooldown_turns = 3;
        [$actor, $state] = $this->battle(24, [$consumer]);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 3);
        $state->beginSourceAction();
        $before = [$actor->mp, $actor->getResource('star_mark'), $state->jobArtCooldowns, $state->jobArtUseCounts];

        $this->assertFalse($this->support($this->selection([]))->consumeAndMarkUse($actor, $state, $consumer));
        $this->assertSame($before, [$actor->mp, $actor->getResource('star_mark'), $state->jobArtCooldowns, $state->jobArtUseCounts]);
    }

    public function test_pve_normal_hit_awards_one_even_when_resolved_damage_is_zero(): void
    {
        $calculator = Mockery::mock(DamageCalculator::class);
        $calculator->shouldReceive('isHit')->once()->andReturnTrue();
        $calculator->shouldReceive('isCritical')->once()->andReturnFalse();
        $calculator->shouldReceive('calculatePhysicalDamage')->once()->andReturn(0);
        $service = $this->battleService($calculator);
        [$actor, $state] = $this->battle(24, []);
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);

        $service->normalForTest($actor, $state->enemy, $state);

        $this->assertSame(1, $actor->getResource('star_mark'));
        $this->assertSame(100, $state->enemy->hp);
    }

    public function test_pve_normal_miss_does_not_award_resource(): void
    {
        $calculator = Mockery::mock(DamageCalculator::class);
        $calculator->shouldReceive('isHit')->once()->andReturnFalse();
        $service = $this->battleService($calculator);
        [$actor, $state] = $this->battle(62, []);
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);

        $service->normalForTest($actor, $state->enemy, $state);

        $this->assertSame(0, $actor->getResource('dragon_force'));
    }

    public function test_all_six_battle_paths_use_the_shared_resource_rules_at_formal_action_points(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertStringContainsString('beginAction($attacker, $state)', $battle);
        $this->assertStringContainsString('recordNormalAttackResolution($attacker, $defender, $state, HitResult::HIT)', $battle);
        $this->assertStringContainsString('recordSelfDamage($attacker, $state, $selfDamage)', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString("\$battleContext = \$enemy->is_boss ? 'boss' : 'pve';", $battle);
        $this->assertStringContainsString('beginAction($attacker, $state)', $pvp);
        $this->assertStringContainsString('recordNormalAttackResolution($attacker, $defender, $state, HitResult::HIT)', $pvp);
        $this->assertStringContainsString('recordSelfDamage($attacker, $state, $selfDamage)', $pvp);
        $this->assertStringContainsString('beginAction($attacker, $jobArtState)', $champ);
        $this->assertStringContainsString('normalAttackWithResource', $champ);
        $this->assertStringContainsString('recordSelfDamage($attacker, $state, $selfDamage)', $champ);
        $this->assertStringContainsString('beginAction($attacker, $state)', $arenaNpc);
        $this->assertStringContainsString('recordNormalAttackResolution($attacker, $defender, $state, HitResult::HIT)', $arenaNpc);
        $this->assertStringContainsString('recordSelfDamage($attacker, $state, $selfDamage)', $arenaNpc);
    }

    public function test_flag_off_does_not_advance_action_id_mutate_resources_or_consume_rng(): void
    {
        config(['battle.job_art_v2.resources' => false]);
        [$actor, $state] = $this->battle(24, []);
        $before = serialize($state);
        mt_srand(8810);
        $expected = mt_rand();

        mt_srand(8810);
        $this->assertNull(app(JobArtV2ResourceService::class)->beginAction($actor, $state));
        $this->assertFalse(app(JobArtV2ResourceService::class)->recordNormalAttackHit($actor, $state)->applied);
        $this->assertFalse(app(JobArtV2ResourceService::class)->recordSelfDamage($actor, $state, 1)->applied);
        $actual = mt_rand();

        $this->assertSame($before, serialize($state));
        $this->assertSame(0, $actor->getResource('star_mark'));
        $this->assertSame($expected, $actual);
    }

    public function test_resource_state_is_transient_and_no_persistence_contract_is_added(): void
    {
        $actorSource = file_get_contents(base_path('app/Services/Battle/BattleActor.php'));
        $serviceSource = file_get_contents(base_path('app/Services/JobArtV2ResourceService.php'));

        $this->assertStringContainsString('private array $resources = [];', $actorSource);
        $this->assertStringNotContainsString('save(', $serviceSource);
        $this->assertStringNotContainsString('DB::', $serviceSource);
        $this->assertStringNotContainsString('update(', $serviceSource);
    }

    private function battleService(DamageCalculator $calculator): object
    {
        return new class(
            Mockery::mock(CharacterStatusService::class),
            $calculator,
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            app(JobArtV2SelectionService::class),
            app(JobArtV2SpCostCalculator::class),
            null,
            null,
            app(JobArtV2ResourceService::class),
        ) extends BattleService {
            public function normalForTest(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->executeNormalAttack($attacker, $defender, $state);
            }
        };
    }

    private function support(JobArtV2SelectionService $selection): JobArtBattleSupportService
    {
        return new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selection,
            app(JobArtV2SpCostCalculator::class),
            null,
            app(JobArtV2ResourceService::class),
        );
    }

    private function selection(array $rolls): JobArtV2SelectionService
    {
        $random = new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls)
            {
            }

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };

        return new JobArtV2SelectionService(
            $random,
            app(\App\Services\JobArtV2FinisherConditionProvider::class),
            app(JobArtV2SpCostCalculator::class),
            app(\App\Services\JobArtV2BattleRules::class),
            app(JobArtV2ResourceService::class),
        );
    }

    /** @param array<int, Skill> $arts */
    private function battle(int $jobId, array $arts): array
    {
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'agi' => 100,
            'current_job_id' => $jobId,
        ]);
        $actor->jobArts = $arts;
        $actor->jobArtActivationPolicy = 'aggressive';
        $enemy = new BattleActor('enemy', false, [
            'hp' => 100,
            'max_hp' => 100,
            'agi' => 100,
        ]);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function art(int $jobId, int $rank, int $activationRate): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'job_id' => $jobId,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'activation_rate' => $activationRate,
            'sp_cost_fixed' => 1,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'power' => 100,
            'power_multiplier' => 1.0,
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }
}
