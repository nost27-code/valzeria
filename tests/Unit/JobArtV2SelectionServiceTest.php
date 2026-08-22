<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

class JobArtV2SelectionServiceTest extends TestCase
{
    public function test_gate_enables_every_master_current_job_only_when_flag_is_on(): void
    {
        $gate = new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog());
        $actor = $this->actor();
        $supportedRepresentatives = [1, 8, 9, 26, 27, 38, 44, 53, 60, 69, 70, 79, 80, 84, 85, 94, 95, 99];

        config(['battle.job_art_v2.dynamic_single' => false]);
        foreach ($supportedRepresentatives as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertFalse($gate->usesDynamicSingle($actor));
        }

        config(['battle.job_art_v2.dynamic_single' => true]);
        foreach ($supportedRepresentatives as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertTrue($gate->usesDynamicSingle($actor));
        }
        foreach ([39, 40, 41, 42, 43, 100] as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertFalse($gate->usesDynamicSingle($actor));
        }

        config(['battle.job_art_v2.normalized_sp' => true]);
        foreach ($supportedRepresentatives as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertTrue($gate->usesPr5Rules($actor));
        }
        $actor->currentJobId = 39;
        $this->assertFalse($gate->usesPr5Rules($actor));
    }

    public function test_only_first_eligible_candidate_is_rolled_and_miss_never_retries_later_slot(): void
    {
        $random = $this->random([100, 1]);
        $service = $this->service($random);
        [$actor, $state] = $this->battle([$this->art(101, 50), $this->art(102, 100)]);

        $result = $service->selectForTurn($actor, $state);

        $this->assertNull($result->skill);
        $this->assertSame(101, $result->candidateSkillId);
        $this->assertSame(50, $result->activationRate);
        $this->assertFalse($result->activated);
        $this->assertFalse($result->retriedAfterMiss);
        $this->assertSame(1, $random->calls);
    }

    public function test_v2_ignores_legacy_cooldown_and_use_limit_before_one_roll(): void
    {
        $random = $this->random([1]);
        $service = $this->service($random);
        $cooldown = $this->art(201, 100);
        $used = $this->art(202, 100, maxUses: 1);
        $expensive = $this->art(203, 100, spCostFixed: 20);
        $eligible = $this->art(204, 100);
        [$actor, $state] = $this->battle([$cooldown, $used, $expensive, $eligible], mp: 10);
        $state->jobArtCooldowns[201] = 1;
        $state->jobArtUseCounts[202] = 1;

        $result = $service->selectForTurn($actor, $state);

        $this->assertSame(201, $result->skill?->id);
        $this->assertSame(1, $random->calls);
    }

    public function test_v2_still_skips_sp_shortage_after_legacy_limits_are_removed(): void
    {
        $random = $this->random([1]);
        $service = $this->service($random);
        $expensive = $this->art(211, 100, spCostFixed: 20);
        $expensive->job_id = 85;
        $eligible = $this->art(212, 100);
        [$actor, $state] = $this->battle([$expensive, $eligible], mp: 10);

        $result = $service->selectForTurn($actor, $state);

        $this->assertSame(212, $result->skill?->id);
        $this->assertSame(1, $random->calls);
    }

    public function test_repeated_eligibility_checks_are_pure_and_do_not_consume_randomness(): void
    {
        $random = $this->random([1]);
        $service = $this->service($random);
        $skill = $this->art(301, 100);
        [$actor, $state] = $this->battle([$skill]);
        $actorBefore = serialize($actor);
        $stateBefore = serialize($state);

        $this->assertTrue($service->isEligible($actor, $state, $skill, 301));
        $this->assertTrue($service->isEligible($actor, $state, $skill, 301));

        $this->assertSame($actorBefore, serialize($actor));
        $this->assertSame($stateBefore, serialize($state));
        $this->assertSame(0, $random->calls);
    }

    public function test_rank_nine_is_prioritized_only_when_resources_and_condition_are_both_enabled(): void
    {
        // 資源満タン時の始動は上限で適格外になるため、通常順の比較札には
        // 同じ資源を消費できる連携を置く。
        $front = $this->art(401, 100, learnRank: 5);
        $rankNine = $this->art(409, 100, learnRank: 9);
        $front->job_id = 11;
        // 場術の奥義は展開中の場も必要になるため、汎用の優先順だけを
        // 検証するこのテストでは追加適格条件を持たない反撃奥義を使う。
        $rankNine->job_id = 11;

        config(['battle.job_art_v2.resources' => false]);
        $provider = $this->finisherProvider(true);
        $service = $this->service($this->random([1]), $provider);
        [$actor, $state] = $this->battle([$front, $rankNine]);
        $actor->currentJobId = 11;
        $offResult = $service->selectForTurn($actor, $state);
        $this->assertSame(401, $offResult->skill?->id);
        $this->assertFalse($offResult->rankNinePrioritized);
        $this->assertSame(0, $provider->calls);

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
        $actor->configureResource('sword_momentum', 12);
        $actor->setResource('sword_momentum', 12);
        $actor->setJobArtV2SelectionCursor(0, 2);
        $falseProvider = $this->finisherProvider(false);
        $falseService = $this->service($this->random([1]), $falseProvider);
        $this->assertNull($falseService->eligibilityFailureReason($actor, $state, $rankNine, 409));
        $falseResult = $falseService->selectForTurn($actor, $state);
        $this->assertSame(401, $falseResult->skill?->id, 'condition false must preserve cursor order');
        $this->assertFalse($falseResult->rankNinePrioritized);
        $this->assertSame(1, $falseProvider->calls);

        $trueProvider = $this->finisherProvider(true);
        $trueResult = $this->service($this->random([1]), $trueProvider)->selectForTurn($actor, $state);
        $this->assertSame(1, $trueProvider->calls);
        $this->assertSame(409, $trueResult->skill?->id, 'condition true must prioritize the ready ultimate');
        $this->assertTrue($trueResult->rankNinePrioritized);
    }

    public function test_normal_cursor_resumes_with_the_starter_after_one_ready_finisher_attempt(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);

        $frontInherited = $this->art(451, 100, learnRank: 1);
        $frontInherited->job_id = 65;
        $inheritedRankNine = $this->art(459, 100, learnRank: 9);
        $inheritedRankNine->job_id = 65;
        [$actor, $state] = $this->battle([$frontInherited, $inheritedRankNine]);
        $actor->currentJobId = 68;
        $actor->jobArtOrigins[451] = 'inherited';
        $actor->jobArtOrigins[459] = 'inherited';
        $actor->configureResource('break', 12);
        $actor->setResource('break', 12);
        $actor->configureResource('aim', 12);
        $actor->setResource('aim', 12);

        $inheritedOnly = $this->service($this->random([1]))->selectForTurn($actor, $state);
        $this->assertSame(459, $inheritedOnly->skill?->id);
        $this->assertTrue($inheritedOnly->rankNinePrioritized);

        $currentRankNine = $this->art(469, 100, learnRank: 9);
        $currentRankNine->job_id = 68;
        $actor->jobArts[] = $currentRankNine;
        $actor->jobArtOrigins[469] = 'current';

        $withCurrentFinisher = $this->service($this->random([1]))->selectForTurn($actor, $state);
        $this->assertSame(451, $withCurrentFinisher->skill?->id);
        $this->assertFalse($withCurrentFinisher->rankNinePrioritized);
    }

    public function test_prioritized_ultimate_miss_is_logged_then_the_starter_is_considered_next(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);

        $front = $this->art(501, 100, learnRank: 1);
        $front->job_id = 11;
        $ultimate = $this->art(509, 100, learnRank: 9);
        $ultimate->job_id = 11;
        $ultimate->name = '聖壁<アルカディア>';
        [$actor, $state] = $this->battle([$front, $ultimate]);
        $actor->currentJobId = 11;
        $actor->configureResource('sword_momentum', 12);
        $actor->setResource('sword_momentum', 12);

        $service = $this->service($this->random([100, 1]), $this->finisherProvider(true));
        $first = $service->selectForTurn($actor, $state);
        $second = $service->selectForTurn($actor, $state);

        $this->assertNull($first->skill);
        $this->assertSame(509, $first->candidateSkillId);
        $this->assertSame(60, $first->activationRate);
        $this->assertTrue($first->rankNinePrioritized);
        $this->assertFalse($first->retriedAfterMiss);
        $this->assertSame(501, $second->skill?->id);
        $this->assertSame(501, $second->candidateSkillId);
        $this->assertSame(50, $second->activationRate);
        $this->assertFalse($second->rankNinePrioritized);
        $this->assertFalse($second->retriedAfterMiss);
        $matchingLogs = array_values(array_filter(
            $state->logs,
            static fn (string $log): bool => str_contains($log, '聖壁&lt;アルカディア&gt;')
                && str_contains($log, '発動率60%')
                && str_contains($log, '次の行動は通常の候補順に戻る'),
        ));
        $this->assertCount(1, $matchingLogs);
        $this->assertStringNotContainsString('聖壁<アルカディア>', implode("\n", $state->logs));
    }

    public function test_pr5_uses_rank_activation_rates_for_every_completed_job(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.resources' => false,
        ]);

        foreach ([24, 53, 60, 61, 62, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            foreach ([1 => 50, 5 => 55, 9 => 60] as $rank => $expectedRate) {
                $skill = $this->art(($jobId * 10) + $rank, 91, learnRank: $rank);
                $skill->job_id = $jobId;
                $activationRate = app(JobArtV2BattleRules::class)->activationRateFor(
                    $skill,
                    $jobId,
                    'current',
                );

                $this->assertSame(
                    $expectedRate,
                    $activationRate,
                    "job {$jobId} rank {$rank} must use the v2 activation rate",
                );
                $this->assertSame(91, $skill->effectiveActivationRate());
            }
        }
    }

    public function test_inherited_candidate_uses_v2_rank_activation_rate_in_mixed_loadout(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);

        $inherited = $this->art(581, 17, learnRank: 5);
        $inherited->job_id = 65;
        [$actor, $state] = $this->battle([$inherited]);
        $actor->currentJobId = 68;
        $actor->jobArtOrigins[581] = 'inherited';
        $actor->configureResource('aim', 12);
        $actor->setResource('aim', 4);

        $result = $this->service($this->random([1]))->selectForTurn($actor, $state);

        $this->assertSame(581, $result->skill?->id);
        $this->assertSame(55, $result->activationRate);
        $this->assertSame(17, $inherited->effectiveActivationRate());
    }

    public function test_conserve_accepts_exactly_sixty_percent_but_not_just_below(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $skill = $this->art(601, 100, learnRank: 1);
        $skill->job_id = 24;
        $service = $this->service($this->random([]));

        foreach ([59 => false, 60 => true, 61 => true] as $mp => $expected) {
            [$actor, $state] = $this->battle([$skill], mp: $mp);
            $actor->currentJobId = 24;
            $actor->jobArtActivationPolicy = 'conserve';

            $this->assertSame($expected, $service->isEligible($actor, $state, $skill, 601), "SP{$mp}");
        }
    }

    public function test_hidden_mark_gate_is_reported_once_until_the_visible_count_changes(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
        $skill = $this->art(6405, 100, learnRank: 5);
        $skill->job_id = 64;
        $skill->name = '影冠狙撃';
        [$actor, $state] = $this->battle([$skill]);
        $actor->currentJobId = 64;
        $actor->jobArtOrigins[6405] = 'current';
        $actor->configureResource('hunt', 12);
        $actor->setResource('hunt', 4);
        $service = $this->service($this->random([]));

        $this->assertNull($service->selectForTurn($actor, $state)->skill);
        $this->assertNull($service->selectForTurn($actor, $state)->skill);

        $matchingLogs = array_values(array_filter(
            $state->logs,
            static fn (string $log): bool => str_contains($log, '標的印 が不足')
                && str_contains($log, '必要 1／現在 0'),
        ));
        $this->assertCount(1, $matchingLogs);
    }

    private function service(
        JobArtV2RandomSource $random,
        ?JobArtV2FinisherConditionProvider $provider = null,
    ): JobArtV2SelectionService {
        return new JobArtV2SelectionService(
            $random,
            $provider ?? new JobArtV2FinisherConditionProvider(),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
        );
    }

    private function random(array $rolls): JobArtV2RandomSource
    {
        return new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private array $rolls)
            {
            }

            public function percentRoll(): int
            {
                $roll = $this->rolls[$this->calls] ?? 100;
                $this->calls++;

                return $roll;
            }
        };
    }

    private function finisherProvider(bool $satisfied): JobArtV2FinisherConditionProvider
    {
        return new class($satisfied) extends JobArtV2FinisherConditionProvider
        {
            public int $calls = 0;

            public function __construct(private readonly bool $satisfied)
            {
            }

            public function isSatisfied(BattleActor $actor, BattleState $state, Skill $skill): bool
            {
                $this->calls++;

                return $this->satisfied;
            }
        };
    }

    /** @param array<int, Skill> $arts */
    private function battle(array $arts, int $mp = 100): array
    {
        $actor = $this->actor($mp);
        $actor->jobArts = $arts;
        $enemy = new BattleActor('enemy', false, ['hp' => 100, 'max_hp' => 100]);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function actor(int $mp = 100): BattleActor
    {
        $actor = new BattleActor('player', true, [
            'hp' => 80,
            'max_hp' => 100,
            'mp' => $mp,
            'max_mp' => 100,
        ]);
        $actor->jobArtActivationPolicy = 'aggressive';

        return $actor;
    }

    private function art(
        int $id,
        int $activationRate,
        ?int $maxUses = null,
        ?int $spCostFixed = null,
        int $learnRank = 1,
    ): Skill {
        $skill = new Skill([
            'name' => "art-{$id}",
            'job_id' => 1,
            'skill_type' => 'job_art',
            'learn_rank' => $learnRank,
            'activation_rate' => $activationRate,
            'max_uses_per_battle' => $maxUses,
            'sp_cost_fixed' => $spCostFixed,
            'effect_template' => 'DAMAGE',
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }
}
