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
    public function test_gate_enables_only_the_completed_current_jobs_when_flag_is_on(): void
    {
        $gate = new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog());
        $actor = $this->actor();

        config(['battle.job_art_v2.dynamic_single' => false]);
        foreach ([24, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertFalse($gate->usesDynamicSingle($actor));
        }

        config(['battle.job_art_v2.dynamic_single' => true]);
        foreach ([24, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertTrue($gate->usesDynamicSingle($actor));
        }
        foreach ([1, 23, 25, 39, 70, 84, 86, 94] as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertFalse($gate->usesDynamicSingle($actor));
        }

        config(['battle.job_art_v2.normalized_sp' => true]);
        foreach ([24, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            $actor->currentJobId = $jobId;
            $this->assertTrue($gate->usesPr5Rules($actor));
        }
        $actor->currentJobId = 10;
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

    public function test_ineligible_cooldown_use_limit_and_sp_shortage_are_skipped_before_one_roll(): void
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

        $this->assertSame(204, $result->skill?->id);
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
        $front = $this->art(401, 100, learnRank: 1);
        $rankNine = $this->art(409, 100, learnRank: 9);
        $front->job_id = 999;
        $rankNine->job_id = 24;

        config(['battle.job_art_v2.resources' => false]);
        $provider = $this->finisherProvider(true);
        $service = $this->service($this->random([1]), $provider);
        [$actor, $state] = $this->battle([$front, $rankNine]);
        $actor->currentJobId = 24;
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
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);
        $falseProvider = $this->finisherProvider(false);
        $falseResult = $this->service($this->random([1]), $falseProvider)->selectForTurn($actor, $state);
        $this->assertSame(401, $falseResult->skill?->id);
        $this->assertFalse($falseResult->rankNinePrioritized);

        $trueProvider = $this->finisherProvider(true);
        $trueResult = $this->service($this->random([1]), $trueProvider)->selectForTurn($actor, $state);
        $this->assertSame(409, $trueResult->skill?->id);
        $this->assertTrue($trueResult->rankNinePrioritized);
    }

    public function test_only_current_trusted_rank_nine_receives_finisher_priority_in_mixed_loadouts(): void
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

        $inheritedOnly = $this->service($this->random([1]))->selectForTurn($actor, $state);
        $this->assertSame(451, $inheritedOnly->skill?->id);
        $this->assertFalse($inheritedOnly->rankNinePrioritized);

        $currentRankNine = $this->art(469, 100, learnRank: 9);
        $currentRankNine->job_id = 68;
        $actor->jobArts[] = $currentRankNine;
        $actor->jobArtOrigins[469] = 'current';

        $withCurrentFinisher = $this->service($this->random([1]))->selectForTurn($actor, $state);
        $this->assertSame(469, $withCurrentFinisher->skill?->id);
        $this->assertTrue($withCurrentFinisher->rankNinePrioritized);
    }

    public function test_pr5_uses_rank_activation_rates_for_every_completed_job(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);

        foreach ([24, 53, 60, 61, 62, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            foreach ([1 => 35, 5 => 38, 9 => 50] as $rank => $expectedRate) {
                $skill = $this->art(($jobId * 10) + $rank, 91, learnRank: $rank);
                $skill->job_id = $jobId;
                [$actor, $state] = $this->battle([$skill]);
                $actor->currentJobId = $jobId;
                $result = $this->service($this->random([1]))->selectForTurn($actor, $state);

                $this->assertSame($expectedRate, $result->activationRate);
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

        $result = $this->service($this->random([1]))->selectForTurn($actor, $state);

        $this->assertSame(581, $result->skill?->id);
        $this->assertSame(38, $result->activationRate);
        $this->assertSame(17, $inherited->effectiveActivationRate());
    }

    public function test_pr5_conserve_accepts_exactly_forty_percent_but_not_just_below(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $skill = $this->art(601, 100, learnRank: 1);
        $skill->job_id = 24;
        $service = $this->service($this->random([]));

        foreach ([39 => false, 40 => true, 41 => true] as $mp => $expected) {
            [$actor, $state] = $this->battle([$skill], mp: $mp);
            $actor->currentJobId = 24;
            $actor->jobArtActivationPolicy = 'conserve';

            $this->assertSame($expected, $service->isEligible($actor, $state, $skill, 601), "SP{$mp}");
        }
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
