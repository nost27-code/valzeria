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
        $this->assertFalse($this->service()->enabledFor($this->actor(39)));
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
                $this->assertSame(match ($rank) { 1 => 4, default => 0 }, $metadata['resource_gain_points']);
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

    public function test_only_resources_explicitly_used_by_the_equipped_arts_are_active(): void
    {
        $catalog = app(JobArtV2ResourceCatalog::class);
        $actor = $this->actor(12);

        $neutral = $this->art(12, 1);
        $actor->jobArts = [$neutral];
        $this->assertFalse($catalog->activatesResource($actor, $neutral));
        $this->assertSame([], $catalog->resourcesForActor($actor));
        $this->assertSame([], $catalog->resourcesForSkills(12, [$neutral]));

        $consumer = $this->art(61, 5);
        $actor->jobArtOrigins[(int) $consumer->id] = 'inherited';
        $actor->jobArts = [$neutral, $consumer];
        $this->assertTrue($catalog->activatesResource($actor, $consumer));
        $this->assertSame('eclipse', $catalog->resourcesForActor($actor)[0]['resource_key']);
        $this->assertSame('eclipse', $catalog->resourcesForSkills(12, [$neutral, $consumer])[0]['resource_key']);

        $ultimate = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $ultimate->id] = 'inherited';
        $actor->jobArts = [$neutral, $ultimate];
        $this->assertTrue($catalog->activatesResource($actor, $ultimate));
        $this->assertSame('aim', $catalog->resourcesForActor($actor)[0]['resource_key']);
        $this->assertSame('aim', $catalog->resourcesForSkills(12, [$neutral, $ultimate])[0]['resource_key']);
    }

    public function test_incoming_damage_resource_logs_are_flushed_after_the_damage_log(): void
    {
        [$actor, $state] = $this->battle(60);
        $service = $this->service();
        $actor->jobArts = [$this->art(60, 1)];
        $service->beginAction($actor, $state);

        $received = $service->recordDirectAttackDamageReceived($actor, $state);
        $parried = $service->recordParrySuccess($actor, $state);

        $this->assertSame(1, $received->delta);
        $this->assertSame(1, $parried->delta);
        $this->assertSame(2, $actor->getResource('sword_momentum'));
        $this->assertSame([], $state->logs);

        $state->addDamageLog('敵の攻撃！ 100 のダメージ！');

        $this->assertSame('敵の攻撃！ 100 のダメージ！', $state->logs[0]);
        $this->assertStringContainsString('剣勢 +1', $state->logs[1]);
        $this->assertStringContainsString('剣勢 +1', $state->logs[2]);
        $this->assertSame([], $state->pullDeferredDamageLogs());
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
        $actor->jobArts = [$this->art(24, 1)];
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
            'direct_attack_damage_received',
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
        $actor->jobArts = [$rankOne];
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

    public function test_direct_eclipse_gain_and_common_self_damage_do_not_stack_in_the_same_art_action(): void
    {
        [$actor, $state] = $this->battle(61);
        $service = $this->service();
        $rankOne = $this->art(61, 1);
        $actor->jobArts = [$rankOne];
        $service->beginAction($actor, $state);

        $service->applyJobArtCast($actor, $state, $rankOne);
        $hit = $service->recordJobArtHit($actor, $state, $rankOne);
        $selfDamage = $service->recordSelfDamage($actor, $state, 1);
        $duplicateSelfDamage = $service->recordSelfDamage($actor, $state, 1);

        $this->assertSame(4, $hit->delta);
        $this->assertFalse($selfDamage->applied);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_DIRECT_RESOURCE_OPERATION, $selfDamage->blockedReason);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertFalse($duplicateSelfDamage->applied);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_DIRECT_RESOURCE_OPERATION, $duplicateSelfDamage->blockedReason);

        $service->beginAction($actor, $state);
        $nextActionSelfDamage = $service->recordSelfDamage($actor, $state, 1);
        $this->assertSame(2, $nextActionSelfDamage->delta);
        $this->assertSame(ResourceEvent::SELF_DAMAGE, $nextActionSelfDamage->event);
        $this->assertSame(6, $actor->getResource('eclipse'));

        [$hunt, $huntState] = $this->battle(64);
        $hunt->jobArts = [$this->art(64, 1)];
        $service->beginAction($hunt, $huntState);
        $this->assertFalse($service->recordSelfDamage($hunt, $huntState, 1)->applied);
        $this->assertSame(0, $hunt->getResource('eclipse'));
        $this->assertSame(0, $hunt->getResource('hunt'));

        [$zeroDamage, $zeroDamageState] = $this->battle(61);
        $service->beginAction($zeroDamage, $zeroDamageState);
        $this->assertFalse($service->recordSelfDamage($zeroDamage, $zeroDamageState, 0)->applied);
        $this->assertSame(0, $zeroDamage->getResource('eclipse'));
    }

    public function test_blood_roar_keeps_self_damage_history_but_ends_at_eclipse_plus_four(): void
    {
        [$actor, $state] = $this->battle(14);
        $service = $this->service();
        $bloodRoar = $this->art(14, 1);
        $bloodRoar->name = '血潮の咆哮';
        $actor->jobArts = [$bloodRoar];

        $service->beginAction($actor, $state);
        $directGain = $service->applyJobArtCast($actor, $state, $bloodRoar);
        $commonGain = $service->recordSelfDamage($actor, $state, 3);

        $this->assertSame(4, $directGain->delta);
        $this->assertFalse($commonGain->applied);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_DIRECT_RESOURCE_OPERATION, $commonGain->blockedReason);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertSame(3, $actor->jobArtV2ProgressionState()->nightmareSelfDamage);
    }

    public function test_eclipse_consumer_suppresses_same_action_self_damage_but_not_the_next_action(): void
    {
        [$actor, $state] = $this->battle(61);
        $service = $this->service();
        $consumer = $this->art(61, 5);
        $actor->jobArts = [$consumer];
        $actor->configureResource('eclipse', 12);
        $actor->setResource('eclipse', 4);

        $service->beginAction($actor, $state);
        $this->assertSame(-4, $service->applyJobArtCast($actor, $state, $consumer)->delta);
        $blocked = $service->recordSelfDamage($actor, $state, 2);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_DIRECT_RESOURCE_OPERATION, $blocked->blockedReason);
        $this->assertSame(0, $actor->getResource('eclipse'));

        $service->beginAction($actor, $state);
        $this->assertSame(2, $service->recordSelfDamage($actor, $state, 2)->delta);
        $this->assertSame(2, $actor->getResource('eclipse'));
    }

    public function test_direct_eclipse_operation_does_not_suppress_a_different_active_resource(): void
    {
        [$actor, $state] = $this->battle(14);
        $service = $this->service();
        $bloodRoar = $this->art(14, 1);
        $guardConsumer = $this->art(15, 5);
        $actor->jobArtOrigins[(int) $guardConsumer->id] = 'inherited';
        $actor->jobArts = [$bloodRoar, $guardConsumer];

        $service->beginAction($actor, $state);
        $this->assertSame(4, $service->applyJobArtCast($actor, $state, $bloodRoar)->delta);
        $guardGain = $service->recordDamageMitigated($actor, $state);

        $this->assertSame(1, $guardGain->delta);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertSame(1, $actor->getResource('holy_guard'));
    }

    public function test_explicit_metadata_can_allow_same_action_common_gain_for_a_future_art(): void
    {
        $metadata = array_merge(
            app(JobArtV2PrototypeCatalog::class)->artResourceMetadataForJobRank(14, 1),
            ['allow_lineage_common_gain' => true],
        );
        $catalog = new class($metadata) extends JobArtV2ResourceCatalog
        {
            /** @param array<string, int|float|string|bool> $metadata */
            public function __construct(private readonly array $metadata)
            {
            }

            public function forActorArt(BattleActor $actor, Skill $skill): ?array
            {
                return $this->metadata;
            }

            public function usesBattleResource(BattleActor $actor, Skill $skill): bool
            {
                return true;
            }

            public function resourcesForActor(BattleActor $actor): array
            {
                return [$this->metadata];
            }
        };
        $service = new JobArtV2ResourceService(
            app(\App\Services\JobArtV2FeatureGate::class),
            $catalog,
            app(\App\Services\JobArtV2FieldService::class),
            app(\App\Services\JobArtV2BattleHudService::class),
            app(\App\Services\JobArtV2ConversionService::class),
            app(\App\Services\JobArtV2ProgressionService::class),
        );
        [$actor, $state] = $this->battle(14);
        $bloodRoar = $this->art(14, 1);
        $actor->jobArts = [$bloodRoar];

        $service->beginAction($actor, $state);
        $this->assertSame(4, $service->applyJobArtCast($actor, $state, $bloodRoar)->delta);
        $this->assertSame(2, $service->recordSelfDamage($actor, $state, 3)->delta);
        $this->assertSame(6, $actor->getResource('eclipse'));
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

    public function test_producer_remains_eligible_at_cap_when_an_equipped_finisher_uses_the_same_resource(): void
    {
        [$actor] = $this->battle(53);
        $producer = $this->art(53, 1);
        $finisher = $this->art(53, 9);
        $actor->jobArts = [$producer, $finisher];
        $actor->jobArtOrigins[(int) $producer->id] = 'current';
        $actor->jobArtOrigins[(int) $finisher->id] = 'current';
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $producer));
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

    public function test_cross_lineage_inherited_arts_build_require_and_spend_their_source_resource(): void
    {
        [$actor, $state] = $this->battle(68);
        $inheritedAimProducer = $this->art(65, 1);
        $inheritedAimFinisher = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $inheritedAimProducer->id] = 'inherited';
        $actor->jobArtOrigins[(int) $inheritedAimFinisher->id] = 'inherited';
        $actor->jobArts = [$inheritedAimProducer, $inheritedAimFinisher];
        $actor->configureResource('break', 12);
        $actor->setResource('break', 12);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $inheritedAimProducer));
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $this->service()->eligibilityBlockReason($actor, $inheritedAimFinisher));
        $this->assertFalse($this->service()->isFinisherReady($actor, $inheritedAimFinisher));
        $this->assertFalse(app(JobArtV2ResourceCatalog::class)->usesPrimaryResource($actor, $inheritedAimProducer));
        $this->assertTrue(app(JobArtV2ResourceCatalog::class)->usesBattleResource($actor, $inheritedAimProducer));

        foreach ([4, 8, 12] as $expected) {
            $this->service()->beginAction($actor, $state);
            $this->assertSame(4, $this->service()->applyJobArtCast($actor, $state, $inheritedAimProducer)->delta);
            $this->assertSame($expected, $actor->getResource('aim'));
        }
        $this->assertSame(12, $actor->getResource('break'));
        $this->assertTrue($this->service()->isFinisherReady($actor, $inheritedAimFinisher));

        $this->service()->beginAction($actor, $state);
        $this->assertSame(-12, $this->service()->applyJobArtCast($actor, $state, $inheritedAimFinisher)->delta);
        $this->assertSame(12, $actor->getResource('break'));
        $this->assertSame(0, $actor->getResource('aim'));
    }

    public function test_passive_events_apply_to_the_equipped_foreign_lineage_resource(): void
    {
        [$actor, $state] = $this->battle(68);
        $producer = $this->art(65, 1);
        $actor->jobArtOrigins[(int) $producer->id] = 'inherited';
        $actor->jobArts = [$producer];

        $this->service()->beginAction($actor, $state);
        $this->service()->applyJobArtCast($actor, $state, $producer);
        $this->assertSame(4, $actor->getResource('aim'));

        $this->service()->beginAction($actor, $state);
        $this->service()->recordNormalAttackHit($actor, $state);
        $this->assertSame(0, $actor->getResource('break'));
        $this->assertSame(5, $actor->getResource('aim'));
    }

    public function test_cross_lineage_resource_chain_is_completely_disabled_with_the_resource_flag_off(): void
    {
        [$actor, $state] = $this->battle(68);
        $producer = $this->art(65, 1);
        $finisher = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $producer->id] = 'inherited';
        $actor->jobArtOrigins[(int) $finisher->id] = 'inherited';
        config(['battle.job_art_v2.resources' => false]);

        $this->assertFalse($this->service()->enabledFor($actor));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $finisher));
        $this->assertFalse($this->service()->applyJobArtCast($actor, $state, $producer)->applied);
        $this->assertSame(0, $actor->getResource('aim'));
        $this->assertSame(0, $actor->resourceCap('aim'));
    }

    public function test_unmapped_inherited_arts_keep_legacy_eligibility_for_every_rank(): void
    {
        $actor = $this->actor(24);

        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(39, 1)));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(39, 5)));
        $this->assertNull($this->service()->eligibilityBlockReason($actor, $this->art(39, 9)));
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
            $actor->jobArts = [$rank1, $rank5, $rank9];

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
            $this->assertNull($service->eligibilityBlockReason($actor, $rank1));
            $this->assertTrue($service->isFinisherReady($actor, $rank9));
            $cast($rank9); $this->assertSame(0, $actor->getResource($resourceKey));
        }
    }

    public function test_breathing_heal_gains_break_on_cast_without_a_hit_result(): void
    {
        [$actor, $state] = $this->battle(68);
        $breathing = $this->art(21, 1);
        $breathing->name = '練気呼吸';
        $breathing->effect_template = 'HEAL';
        $breathing->damage_type = 'heal';
        $breathing->hit_count = 0;
        $actor->jobArtOrigins[(int) $breathing->id] = 'inherited';

        $this->service()->beginAction($actor, $state);
        $cast = $this->service()->applyJobArtCast($actor, $state, $breathing);

        $this->assertTrue($cast->applied);
        $this->assertSame(4, $cast->delta);
        $this->assertSame(4, $actor->getResource('break'));
        $this->assertFalse($this->service()->recordJobArtHit($actor, $state, $breathing)->applied);
        $this->assertSame(4, $actor->getResource('break'));
    }

    public function test_dark_contract_gains_eclipse_on_cast_without_a_hit_result_or_double_gain(): void
    {
        [$actor, $state] = $this->battle(30);
        $contract = $this->art(30, 1);
        $contract->name = '闇の契約';
        $contract->effect_template = 'SELF_BUFF';
        $contract->damage_type = 'support';
        $contract->hit_count = 0;
        $actor->jobArtOrigins[(int) $contract->id] = 'current';

        $this->service()->beginAction($actor, $state);
        $cast = $this->service()->applyJobArtCast($actor, $state, $contract);

        $this->assertTrue($cast->applied);
        $this->assertSame(4, $cast->delta);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertFalse($this->service()->recordJobArtHit($actor, $state, $contract)->applied);
        $this->assertSame(4, $actor->getResource('eclipse'));
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
