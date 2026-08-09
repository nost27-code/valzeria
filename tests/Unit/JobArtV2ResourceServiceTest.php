<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\ResourceEvent;
use App\Services\ResourceRole;
use Tests\TestCase;

class JobArtV2ResourceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
    }

    public function test_resources_are_default_off_and_fail_closed_on_every_dependency(): void
    {
        $config = require base_path('config/battle.php');
        $this->assertFalse($config['job_art_v2']['resources']);

        $actor = $this->actor(24);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            $flags = [
                'dynamic_single' => true,
                'hit_resolution' => true,
                'damage_application' => true,
                'resources' => true,
            ];
            $flags[$flag] = false;
            foreach ($flags as $key => $enabled) {
                config(["battle.job_art_v2.{$key}" => $enabled]);
            }
            $this->assertFalse($this->service()->enabledFor($actor), $flag);
        }

        config(['battle.job_art_v2.resources' => true]);
        $this->assertFalse($this->service()->enabledFor($this->actor(23)));
    }

    public function test_catalog_contains_trusted_rank_profiles_for_all_mapped_lineage_jobs(): void
    {
        $catalog = app(JobArtV2ResourceCatalog::class);

        foreach ([
            24 => 'star_mark',
            53 => 'star_mark',
            61 => 'eclipse',
            62 => 'dragon_force',
            64 => 'hunt',
            65 => 'aim',
            69 => 'command_points',
            85 => 'star_mark',
        ] as $jobId => $resourceKey) {
            foreach ([1 => ResourceRole::PRODUCER, 5 => ResourceRole::CONSUMER, 9 => ResourceRole::FINISHER] as $rank => $role) {
                $skill = $this->art($jobId, $rank);
                $metadata = $catalog->forArt($skill);
                $this->assertSame($resourceKey, $metadata['resource_key']);
                $this->assertSame($role, $catalog->roleForArt($skill));
                $this->assertSame(12, $metadata['resource_max_points']);
                $this->assertSame($jobId === 69 ? 0 : match ($rank) { 1 => 4, default => 0 }, $metadata['resource_gain_points']);
                $this->assertSame(match ($rank) { 5 => 4, 9 => 12, default => 0 }, $metadata['resource_cost_points']);
            }
        }

        $this->assertSame('star_mark', $catalog->forArt($this->art(23, 9))['resource_key']);
        $this->assertNull($catalog->forArt($this->art(39, 9)));
    }

    public function test_battle_actor_clamps_resources_and_never_spends_below_zero(): void
    {
        $actor = $this->actor(24);
        $actor->configureResource('star_mark', 12);

        $this->assertSame(0, $actor->getResource('star_mark'));
        $this->assertSame(12, $actor->resourceCap('star_mark'));
        $this->assertSame(12, $actor->setResource('star_mark', 99));
        $this->assertSame(0, $actor->setResource('star_mark', -5));
        $this->assertFalse($actor->spendResource('star_mark', 1));
        $this->assertSame(0, $actor->getResource('star_mark'));
    }

    public function test_producer_cast_adds_four_once_for_hit_miss_evade_non_damage_and_multi_hit_cases(): void
    {
        foreach (['hit', 'miss', 'evade', 'non_damage', 'multi_hit'] as $case) {
            [$actor, $state] = $this->battle(24);
            $skill = $this->art(24, 1);
            $this->service()->beginAction($actor, $state);

            $first = $this->service()->applyJobArtCast($actor, $state, $skill);
            $duplicate = $this->service()->applyJobArtCast($actor, $state, $skill);

            $this->assertTrue($first->applied, $case);
            $this->assertSame(ResourceEvent::JOB_ART_CAST, $first->event, $case);
            $this->assertSame(4, $actor->getResource('star_mark'), $case);
            $this->assertFalse($duplicate->applied, $case);
            $this->assertSame('duplicate_resource_event', $duplicate->blockedReason, $case);
        }
    }

    public function test_normal_attack_hit_adds_one_once_independent_of_actual_hp_loss(): void
    {
        [$actor, $state] = $this->battle(24);
        $service = $this->service();
        $service->beginAction($actor, $state);

        $hitWithZeroHpLoss = $service->recordNormalAttackHit($actor, $state);
        $duplicateMultiHitNotification = $service->recordNormalAttackHit($actor, $state);

        $this->assertTrue($hitWithZeroHpLoss->applied);
        $this->assertSame(1, $actor->getResource('star_mark'));
        $this->assertFalse($duplicateMultiHitNotification->applied);
        $this->assertSame(1, $actor->getResource('star_mark'));

        $service->beginAction($actor, $state);
        $this->assertTrue($service->recordNormalAttackHit($actor, $state)->applied);
        $this->assertSame(2, $actor->getResource('star_mark'));
    }

    public function test_only_the_frozen_resource_events_have_entry_points(): void
    {
        $source = file_get_contents(base_path('app/Services/JobArtV2ResourceService.php'));

        foreach (['dot', 'percentage', 'valmon', 'job_skill_hit'] as $forbiddenEvent) {
            $this->assertStringNotContainsString("case {$forbiddenEvent}", strtolower($source));
        }
        $this->assertSame(
            [
                'job_art_cast',
                'job_art_hit',
                'self_damage',
                'normal_attack_hit',
                'normal_attack_miss',
                'non_job_art_action',
                'hp_sp_conversion_success',
                'physical_attack_received',
                'parry_success',
                'damage_mitigated',
                'cleanse_success',
            ],
            array_map(static fn (ResourceEvent $event): string => $event->value, ResourceEvent::cases()),
        );
    }

    public function test_eclipse_producer_gains_four_only_after_hit_and_deduplicates_multi_hit(): void
    {
        [$actor, $state] = $this->battle(61);
        $service = $this->service();
        $rankOne = $this->art(61, 1);
        $service->beginAction($actor, $state);

        $cast = $service->applyJobArtCast($actor, $state, $rankOne);
        $afterCast = $actor->getResource('eclipse');
        $hit = $service->recordJobArtHit($actor, $state, $rankOne);
        $duplicateHit = $service->recordJobArtHit($actor, $state, $rankOne);

        $this->assertFalse($cast->applied);
        $this->assertSame(0, $afterCast);
        $this->assertTrue($hit->applied);
        $this->assertSame(ResourceEvent::JOB_ART_HIT, $hit->event);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertFalse($duplicateHit->applied);
        $this->assertSame('duplicate_resource_event', $duplicateHit->blockedReason);
    }

    public function test_eclipse_self_damage_adds_two_once_and_can_coexist_with_job_art_hit(): void
    {
        [$actor, $state] = $this->battle(61);
        $service = $this->service();
        $rankOne = $this->art(61, 1);
        $service->beginAction($actor, $state);

        $hit = $service->recordJobArtHit($actor, $state, $rankOne);
        $selfDamage = $service->recordSelfDamage($actor, $state, 1);
        $duplicateSelfDamage = $service->recordSelfDamage($actor, $state, 1);

        $this->assertSame(4, $hit->delta);
        $this->assertSame(2, $selfDamage->delta);
        $this->assertSame(ResourceEvent::SELF_DAMAGE, $selfDamage->event);
        $this->assertSame(6, $actor->getResource('eclipse'));
        $this->assertFalse($duplicateSelfDamage->applied);
        $this->assertSame('duplicate_resource_event', $duplicateSelfDamage->blockedReason);

        [$hunt, $huntState] = $this->battle(64);
        $service->beginAction($hunt, $huntState);
        $this->assertFalse($service->recordSelfDamage($hunt, $huntState, 1)->applied);
        $this->assertSame(0, $hunt->getResource('hunt'));

        [$zeroDamage, $zeroDamageState] = $this->battle(61);
        $service->beginAction($zeroDamage, $zeroDamageState);
        $this->assertFalse($service->recordSelfDamage($zeroDamage, $zeroDamageState, 0)->applied);
        $this->assertSame(0, $zeroDamage->getResource('eclipse'));
    }

    public function test_hunt_producer_gains_on_cast_without_waiting_for_hit(): void
    {
        [$actor, $state] = $this->battle(64);
        $service = $this->service();
        $rankOne = $this->art(64, 1);
        $service->beginAction($actor, $state);

        $cast = $service->applyJobArtCast($actor, $state, $rankOne);
        $irrelevantHit = $service->recordJobArtHit($actor, $state, $rankOne);

        $this->assertTrue($cast->applied);
        $this->assertSame(ResourceEvent::JOB_ART_CAST, $cast->event);
        $this->assertSame(4, $actor->getResource('hunt'));
        $this->assertFalse($irrelevantHit->applied);
        $this->assertSame(4, $actor->getResource('hunt'));
    }

    public function test_shared_battle_completion_records_eclipse_hit_but_not_miss(): void
    {
        foreach ([[HitResult::HIT, 4], [HitResult::MISS, 0], [HitResult::EVADE, 0]] as [$hitResult, $expected]) {
            [$actor, $state] = $this->battle(61);
            $rankOne = $this->art(61, 1);
            $this->service()->beginAction($actor, $state);
            $this->service()->applyJobArtCast($actor, $state, $rankOne);

            app(JobArtBattleSupportService::class)->completeJobArtCast(
                $actor,
                $state,
                $rankOne,
                $hitResult,
            );

            $this->assertSame($expected, $actor->getResource('eclipse'), $hitResult->value);
        }
    }

    public function test_producer_caps_at_twelve_and_is_blocked_only_at_cap(): void
    {
        [$actor, $state] = $this->battle(53);
        $skill = $this->art(53, 1);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 11);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $skill));
        $this->service()->beginAction($actor, $state);
        $this->service()->applyJobArtCast($actor, $state, $skill);
        $this->assertSame(12, $actor->getResource('star_mark'));
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_CAP, $this->service()->eligibilityBlockReason($actor, $skill));
    }

    public function test_consumer_and_finisher_require_and_spend_the_frozen_points_once(): void
    {
        foreach ([[5, 4], [9, 12]] as [$rank, $cost]) {
            [$actor, $state] = $this->battle(62);
            $skill = $this->art(62, $rank);
            $actor->configureResource('dragon_force', 12);
            $actor->setResource('dragon_force', $cost - 1);
            $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $this->service()->eligibilityBlockReason($actor, $skill));

            $actor->setResource('dragon_force', $cost);
            $this->assertNull($this->service()->eligibilityBlockReason($actor, $skill));
            $this->service()->beginAction($actor, $state);
            $result = $this->service()->applyJobArtCast($actor, $state, $skill);
            $this->assertSame(-$cost, $result->delta);
            $this->assertSame(0, $actor->getResource('dragon_force'));
            $this->assertFalse($this->service()->applyJobArtCast($actor, $state, $skill)->applied);
        }
    }

    public function test_consumer_and_finisher_spend_on_hit_miss_and_evade_without_refund(): void
    {
        foreach ([[5, 4], [9, 12]] as [$rank, $cost]) {
            foreach (['hit', 'miss', 'evade'] as $resolution) {
                [$actor, $state] = $this->battle(53);
                $actor->configureResource('star_mark', 12);
                $actor->setResource('star_mark', $cost);
                $this->service()->beginAction($actor, $state);

                $result = $this->service()->applyJobArtCast($actor, $state, $this->art(53, $rank));

                $this->assertSame(-$cost, $result->delta, "rank={$rank}:{$resolution}");
                $this->assertSame(0, $actor->getResource('star_mark'), "rank={$rank}:{$resolution}");
            }
        }
    }

    public function test_inherited_arts_use_legacy_eligibility_without_foreign_resource_or_finisher_priority(): void
    {
        foreach ([24, 53, 85] as $currentJobId) {
            foreach ([24, 53, 85] as $artJobId) {
                $actor = $this->actor($currentJobId);
                $skill = $this->art($artJobId, 1);
                $actor->jobArtOrigins[(int) $skill->id] = $artJobId === $currentJobId ? 'current' : 'inherited';
                $this->assertNull($this->service()->eligibilityBlockReason($actor, $skill));
            }
        }

        [$actor, $state] = $this->battle(68);
        $inheritedAimProducer = $this->art(65, 1);
        $inheritedAimFinisher = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $inheritedAimProducer->id] = 'inherited';
        $actor->jobArtOrigins[(int) $inheritedAimFinisher->id] = 'inherited';
        $actor->configureResource('break', 12);
        $actor->setResource('break', 12);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $inheritedAimProducer));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $inheritedAimFinisher));
        $this->assertFalse($this->service()->isFinisherReady($actor, $inheritedAimFinisher));

        $this->service()->beginAction($actor, $state);
        $this->assertFalse($this->service()->applyJobArtCast($actor, $state, $inheritedAimProducer)->applied);
        $this->assertSame(12, $actor->getResource('break'));
        $this->assertSame(0, $actor->getResource('aim'));

        $this->service()->beginAction($actor, $state);
        $this->assertFalse($this->service()->applyJobArtCast($actor, $state, $inheritedAimFinisher)->applied);
        $this->assertSame(12, $actor->getResource('break'));
        $this->assertSame(0, $actor->getResource('aim'));
    }

    public function test_unregistered_inherited_arts_keep_legacy_eligibility_for_every_rank(): void
    {
        $actor = $this->actor(24);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(10, 1)));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(10, 5)));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(10, 9)));
    }

    public function test_star_priest_rank_five_fails_closed_until_trusted_fields_exist(): void
    {
        $actor = $this->actor(85);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 4);

        $this->assertSame(
            JobArtV2ResourceService::BLOCKED_BY_FEATURE_DEPENDENCY,
            $this->service()->eligibilityBlockReason($actor, $this->art(85, 5)),
        );
        $sage = $this->actor(53);
        $sage->configureResource('star_mark', 12);
        $sage->setResource('star_mark', 4);
        $this->assertNull($this->service()->eligibilityBlockReason($sage, $this->art(53, 5)));
    }

    public function test_eligibility_and_resource_service_consume_no_randomness_or_state(): void
    {
        $actor = $this->actor(24);
        $skill = $this->art(24, 1);
        $before = serialize($actor);
        mt_srand(8808);
        $expectedNext = mt_rand();

        mt_srand(8808);
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $skill));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $skill));
        $actualNext = mt_rand();

        $this->assertSame($before, serialize($actor));
        $this->assertSame($expectedNext, $actualNext);
    }

    public function test_star_mark_and_dragon_force_follow_the_deterministic_timeline(): void
    {
        foreach ([[24, 'star_mark'], [62, 'dragon_force']] as [$jobId, $resourceKey]) {
            [$actor, $state] = $this->battle($jobId);
            $service = $this->service();
            $rank1 = $this->art($jobId, 1);
            $rank5 = $this->art($jobId, 5);
            $rank9 = $this->art($jobId, 9);

            $cast = function (Skill $skill) use ($service, $actor, $state): void {
                $service->beginAction($actor, $state);
                $service->applyJobArtCast($actor, $state, $skill);
            };
            $normal = function () use ($service, $actor, $state): void {
                $service->beginAction($actor, $state);
                $service->recordNormalAttackHit($actor, $state);
            };

            $this->assertSame(0, $actor->getResource($resourceKey));
            $cast($rank1); $this->assertSame(4, $actor->getResource($resourceKey));
            $normal(); $this->assertSame(5, $actor->getResource($resourceKey));
            $cast($rank5); $this->assertSame(1, $actor->getResource($resourceKey));
            $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $service->eligibilityBlockReason($actor, $rank9));
            $cast($rank1); $this->assertSame(5, $actor->getResource($resourceKey));
            $normal(); $this->assertSame(6, $actor->getResource($resourceKey));
            $cast($rank1); $this->assertSame(10, $actor->getResource($resourceKey));
            $normal(); $this->assertSame(11, $actor->getResource($resourceKey));
            $cast($rank1); $this->assertSame(12, $actor->getResource($resourceKey));
            $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_CAP, $service->eligibilityBlockReason($actor, $rank1));
            $this->assertTrue($service->isFinisherReady($actor, $rank9));
            $cast($rank9); $this->assertSame(0, $actor->getResource($resourceKey));
        }
    }

    private function service(): JobArtV2ResourceService
    {
        return app(JobArtV2ResourceService::class);
    }

    /** @return array{0: BattleActor, 1: BattleState} */
    private function battle(int $jobId): array
    {
        $actor = $this->actor($jobId);
        $enemy = $this->actor(null, false);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function actor(?int $jobId, bool $isPlayer = true): BattleActor
    {
        return new BattleActor($isPlayer ? 'player' : 'enemy', $isPlayer, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'sp_cost_fixed' => 1,
            'effect_template' => 'PHYSICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }
}
