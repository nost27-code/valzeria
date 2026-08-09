<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PenetrationStanceService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Mockery;
use ReflectionMethod;
use Tests\Support\JobArtV2BattleTrace;
use Tests\TestCase;

class JobArtV2VerticalSliceAcceptanceTest extends TestCase
{
    /** @var array<int, array<int, array<string, mixed>>>|null */
    private static ?array $masterRows = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableAllPrototypeFlags();
    }

    public function test_finisher_pattern_reaches_rank_nine_for_all_four_jobs_with_readable_traces(): void
    {
        foreach ([24, 53, 62, 85] as $jobId) {
            $arts = [$this->art($jobId, 1), $this->art($jobId, 5), $this->art($jobId, 9)];
            [$actor, $target, $state] = $this->battle($jobId, $arts);
            [$service, $selectionRandom] = $this->battleService(array_fill(0, 8, 1), array_fill(0, 8, 1));
            $trace = new JobArtV2BattleTrace();

            for ($turn = 1; $turn <= 4; $turn++) {
                $state->turnCount = $turn;
                $before = $trace->snapshot($actor, $target, $state);
                mt_srand(12_000 + ($jobId * 10) + $turn);
                $service->actForTest($actor, $target, $state);
                $trace->capture($turn, $actor, $target, $state, $before, $arts);
                app(JobArtV2FieldService::class)->endRound($state);
            }

            $rankNine = $arts[2];
            $traceDump = "job {$jobId}\n" . implode("\n", $trace->lines());
            $this->assertSame(1, $state->jobArtUseCounts[(int) $rankNine->id] ?? 0, $traceDump);
            $this->assertSame(0, $actor->getResource($jobId === 62 ? 'dragon_force' : 'star_mark'), $traceDump);
            $this->assertSame(358, $actor->mp, $traceDump);
            $this->assertSame(4, $selectionRandom->calls, $traceDump);
            $this->assertCount(4, $trace->lines(), $traceDump);
            $this->assertStringContainsString('T04 action=' . $rankNine->name, $trace->lines()[3], $traceDump);
            $this->assertStringContainsString('SP=376->358', $trace->lines()[3], $traceDump);
            $this->assertStringContainsString('reason=activated', $trace->lines()[3], $traceDump);

            match ($jobId) {
                24 => $this->assertStringContainsString('field=sanctuary/', $trace->lines()[3]),
                53 => $this->assertStringContainsString('field=star_light/', $trace->lines()[3]),
                62 => $this->assertStringContainsString('stance=off', $trace->lines()[3]),
                85 => $this->assertStringContainsString('overlay=melody/1', $trace->lines()[3]),
            };
        }
    }

    public function test_rank_nine_activation_failure_never_retries_a_later_slot_or_spends_state(): void
    {
        $arts = [$this->art(53, 1), $this->art(53, 5), $this->art(53, 9)];
        [$actor, , $state] = $this->battle(53, $arts);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);
        [$selection, $random] = $this->selection([51, 1]);

        $result = $selection->selectForTurn($actor, $state);

        $this->assertNull($result->skill);
        $this->assertSame($arts[2]->id, $result->candidateSkillId);
        $this->assertTrue($result->rankNinePrioritized);
        $this->assertFalse($result->retriedAfterMiss);
        $this->assertSame(1, $random->calls);
        $this->assertSame(12, $actor->getResource('star_mark'));
        $this->assertSame(400, $actor->mp);
        $this->assertSame([], $state->jobArtUseCounts);
    }

    public function test_cycle_pattern_repeats_rank_five_and_produces_distinct_field_and_stance_traces(): void
    {
        $expectedRankFiveUses = [24 => 2, 53 => 3, 62 => 3, 85 => 3];
        $traces = [];

        foreach ([24, 53, 62, 85] as $jobId) {
            $arts = [$this->art($jobId, 5), $this->art($jobId, 1), $this->art($jobId, 9)];
            [$actor, $target, $state] = $this->battle($jobId, $arts);
            [$service] = $this->battleService(array_fill(0, 12, 1), array_fill(0, 12, 1));
            $trace = new JobArtV2BattleTrace();

            for ($turn = 1; $turn <= 6; $turn++) {
                $state->turnCount = $turn;
                $before = $trace->snapshot($actor, $target, $state);
                mt_srand(22_000 + ($jobId * 10) + $turn);
                $service->actForTest($actor, $target, $state);
                $trace->capture($turn, $actor, $target, $state, $before, $arts);
                app(JobArtV2FieldService::class)->endRound($state);
            }

            $rankFiveId = (int) $arts[0]->id;
            $traces[$jobId] = implode("\n", $trace->lines());
            $traceDump = "job {$jobId}\n" . $traces[$jobId];
            $this->assertSame($expectedRankFiveUses[$jobId], $state->jobArtUseCounts[$rankFiveId] ?? 0, $traceDump);
            $this->assertSame(0, $state->jobArtUseCounts[(int) $arts[2]->id] ?? 0, $traceDump);
        }

        $this->assertStringContainsString('field=star_light/', $traces[53]);
        $this->assertStringContainsString('stance=on', $traces[62]);
        $this->assertStringContainsString('竜冠穿槍', $traces[62]);
        $this->assertStringNotContainsString('竜冠天穿槍', $traces[62]);
    }

    public function test_trusted_conditional_support_is_used_without_inventing_missing_counter_arts(): void
    {
        $priestRankNine = $this->art(24, 9);
        [$priest, , $state] = $this->battle(24, [$priestRankNine]);
        $priest->hp = $priest->maxHp;
        $priest->configureResource('star_mark', 12);
        $priest->setResource('star_mark', 12);
        [$selection] = $this->selection([1]);

        $this->assertSame($priestRankNine, $selection->selectForTurn($priest, $state)->skill);

        $starPriestRankFive = $this->art(85, 5);
        $producer = $this->art(85, 1);
        [$starPriest, , $starState] = $this->battle(85, [$starPriestRankFive, $producer]);
        $starPriest->configureResource('star_mark', 12);
        $starPriest->setResource('star_mark', 4);
        [$fieldSelection] = $this->selection([1]);
        $fieldResult = $fieldSelection->selectForTurn($starPriest, $starState);

        $this->assertSame($producer, $fieldResult->skill);
        $this->assertSame(JobArtV2FieldService::BLOCKED_BY_FIELD, $fieldResult->blockedReasons[(int) $starPriestRankFive->id]);

        $catalog = app(JobArtV2PrototypeCatalog::class);
        foreach ([53, 62] as $jobId) {
            $metadata = $catalog->artResourceMetadata($this->art($jobId, 5));
            $this->assertArrayNotHasKey('requires_trusted_field', $metadata ?? []);
        }
    }

    public function test_priest_support_and_sage_field_rules_hold_in_one_combined_timeline(): void
    {
        $rankOne = $this->art(24, 1);
        $rankFive = $this->art(24, 5);
        $rankNine = $this->art(24, 9);
        [$priest, $target, $state] = $this->battle(24, [$rankOne, $rankFive, $rankNine]);
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);

        $this->applyCast($priest, $state, $rankOne);
        $this->assertSame(4, $priest->getResource('star_mark'));
        $this->assertSame('sanctuary', $state->primaryField()?->key);

        $resources->beginAction($priest, $state);
        $resources->recordNormalAttackHit($priest, $state);
        $this->assertSame(5, $priest->getResource('star_mark'));
        $this->applyCast($priest, $state, $rankFive);
        $this->assertSame(1, $priest->getResource('star_mark'));

        $priest->setResource('star_mark', 12);
        $priest->hp = $priest->maxHp;
        [$selection] = $this->selection([1]);
        $this->assertSame($rankNine, $selection->selectForTurn($priest, $state)->skill);
        $resources->beginAction($priest, $state);
        $fields->markSkillAction($priest, $state, $rankNine);
        $this->assertSame(110, $fields->modifyHpHeal($priest, $state, 100));

        $meaningless = clone $rankNine;
        $meaningless->damage_reduction_percent = 0;
        [$fullHp, , $meaninglessState] = $this->battle(24, [$meaningless]);
        $fullHp->hp = $fullHp->maxHp;
        $fullHp->configureResource('star_mark', 12);
        $fullHp->setResource('star_mark', 12);
        $blocked = $selection->selectForTurn($fullHp, $meaninglessState);
        $this->assertSame('blocked_by_support_condition', $blocked->blockedReasons[(int) $meaningless->id]);

        $hitRandom = new class extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function percentRoll(): int
            {
                $this->calls++;

                return 1;
            }
        };
        $resolver = new ActionResolver(
            app(JobArtV2FeatureGate::class),
            new DamageCalculator(),
            $hitRandom,
            app(JobArtV2ActiveEvasionProvider::class),
            $fields,
        );
        $this->assertNull($resolver->resolveJobArt($priest, $target, $rankNine, 'pve', $state));
        $this->assertSame(0, $hitRandom->calls);

        $sageRankOne = $this->art(53, 1);
        $sageRankFive = $this->art(53, 5);
        [$sage, , $sageState] = $this->battle(53, [$sageRankOne, $sageRankFive]);
        $resources->beginAction($sage, $sageState);
        $resources->applyJobArtCast($sage, $sageState, $sageRankOne);
        $this->assertSame(100, $fields->modifyDamage($sage, $sageState, 100, DamageSourceType::JOB_ART));
        $resources->beginAction($sage, $sageState);
        $fields->markSkillAction($sage, $sageState, $sageRankFive);
        $this->assertSame(110, $fields->modifyDamage($sage, $sageState, 100, DamageSourceType::JOB_ART));
        $resources->applyJobArtCast($sage, $sageState, $sageRankFive);
        $this->assertSame(0, $sage->getResource('star_mark'));
        $this->assertSame(4, $sageState->primaryField()?->remainingRounds);

        [$fieldlessSage, , $fieldlessState] = $this->battle(53, [$sageRankFive]);
        $fieldlessSage->configureResource('star_mark', 12);
        $fieldlessSage->setResource('star_mark', 4);
        $this->assertNull($resources->eligibilityBlockReason($fieldlessSage, $sageRankFive, $fieldlessState));
    }

    public function test_star_priest_overlay_applies_next_action_only_and_expires_normally(): void
    {
        $rankNine = $this->art(85, 9);
        [$actor, , $state] = $this->battle(85, [$rankNine]);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);
        $state->turnCount = 1;
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);

        $resources->beginAction($actor, $state);
        $resources->applyJobArtCast($actor, $state, $rankNine);

        $this->assertSame(50, $fields->activationRate($actor, $state, 50));
        $this->assertSame('melody', $state->fieldOverlay()?->key);
        $resources->beginAction($actor, $state);
        $fields->markSkillAction($actor, $state, $rankNine);
        $this->assertSame(53, $fields->activationRate($actor, $state, 50));
        $state->turnCount = 2;
        $fields->endRound($state);
        $this->assertNull($state->fieldOverlay());
    }

    public function test_rank_nine_damage_has_no_bonus_from_prior_rank_five_use(): void
    {
        $damages = [];
        foreach ([false, true] as $rankFiveUsed) {
            $rankNine = $this->art(62, 9);
            [$actor, $target, $state] = $this->battle(62, [$rankNine]);
            $actor->configureResource('dragon_force', 12);
            $actor->setResource('dragon_force', 12);
            $actor->setPiercingStance(true);
            if ($rankFiveUsed) {
                $state->jobArtUseCounts[(int) $this->art(62, 5)->id] = 1;
            }
            [$service] = $this->battleService([1], [1]);
            mt_srand(62_900);

            $service->actForTest($actor, $target, $state);

            $damages[] = 1_000_000 - $target->hp;
            $this->assertFalse($actor->hasPiercingStance());
            $this->assertSame(0, $actor->getResource('dragon_force'));
        }

        $this->assertSame($damages[0], $damages[1]);
    }

    public function test_same_lineage_inheritance_shares_resource_without_porting_field_operations(): void
    {
        foreach ([[24, 53], [53, 24], [85, 53]] as [$currentJob, $sourceJob]) {
            $skill = $this->art($sourceJob, 1);
            [$actor, , $state] = $this->battle($currentJob, [$skill], 'pve', 'inherited');
            $this->applyCast($actor, $state, $skill);

            $this->assertSame(4, $actor->getResource('star_mark'), "{$currentJob} inherits {$sourceJob}");
            $this->assertNull($state->primaryField(), "{$currentJob} inherits {$sourceJob}");
        }

        $inheritedOverlay = $this->art(85, 9);
        [$sage, , $state] = $this->battle(53, [$inheritedOverlay], 'pve', 'inherited');
        $sage->configureResource('star_mark', 12);
        $sage->setResource('star_mark', 12);
        $this->applyCast($sage, $state, $inheritedOverlay);

        $this->assertSame(0, $sage->getResource('star_mark'));
        $this->assertNull($state->fieldOverlay());
    }

    public function test_cross_lineage_inheritance_uses_legacy_eligibility_without_conversion_or_resonance(): void
    {
        $dragonArt = $this->art(62, 5);
        [$sage, , $fieldState] = $this->battle(53, [$dragonArt], 'pve', 'inherited');
        $sage->configureResource('star_mark', 12);
        $sage->setResource('star_mark', 12);

        $fieldReason = app(JobArtV2ResourceService::class)->eligibilityBlockReason($sage, $dragonArt, $fieldState);

        $fieldArt = $this->art(53, 5);
        [$dragon, , $dragonState] = $this->battle(62, [$fieldArt], 'pve', 'inherited');
        $dragon->configureResource('dragon_force', 12);
        $dragon->setResource('dragon_force', 12);
        $dragonReason = app(JobArtV2ResourceService::class)->eligibilityBlockReason($dragon, $fieldArt, $dragonState);

        $this->assertNull($fieldReason);
        $this->assertNull($dragonReason);
        $this->assertSame(12, $sage->getResource('star_mark'));
        $this->assertSame(12, $dragon->getResource('dragon_force'));
    }

    public function test_field_and_stance_interactions_remain_independent_and_fail_closed(): void
    {
        $sage = $this->actor('sage', 53);
        $starPriest = $this->actor('star-priest', 85);
        $state = new BattleState($sage, $starPriest, 'pvp');
        $sageRankOne = $this->attach($sage, $this->art(53, 1));
        $priestRankOne = $this->attach($starPriest, $this->art(85, 1));
        $priestRankFive = $this->attach($starPriest, $this->art(85, 5));

        $this->applyCast($sage, $state, $sageRankOne);
        $this->applyCast($starPriest, $state, $priestRankOne);
        $this->applyCast($starPriest, $state, $priestRankFive);
        $this->applyCast($sage, $state, $sageRankOne);

        $this->assertSame('star_light', $state->primaryField()?->key);
        $this->assertSame('enemy', $state->primaryField()?->ownerActorKey);
        $this->assertSame(2, $state->primaryField()?->overwriteLockRemainingRounds);
        $this->assertContains(
            JobArtV2FieldService::BLOCKED_BY_FIELD_LOCK,
            array_column(array_map(
                static fn ($event): array => ['reason' => $event->blockedReason],
                $state->fieldEvents(),
            ), 'reason'),
        );
        $state->turnCount = 1;
        app(JobArtV2FieldService::class)->endRound($state);
        $state->turnCount = 2;
        app(JobArtV2FieldService::class)->endRound($state);
        $state->turnCount = 3;
        $this->applyCast($sage, $state, $sageRankOne);
        $this->assertSame('player', $state->primaryField()?->ownerActorKey);

        $priest = $this->actor('priest', 24);
        $otherSage = $this->actor('sage', 53);
        $fieldConflict = new BattleState($priest, $otherSage, 'pvp');
        $this->applyCast($priest, $fieldConflict, $this->attach($priest, $this->art(24, 1)));
        $this->assertSame('sanctuary', $fieldConflict->primaryField()?->key);
        $this->applyCast($otherSage, $fieldConflict, $this->attach($otherSage, $this->art(53, 1)));
        $this->assertSame('star_light', $fieldConflict->primaryField()?->key);
        $eventNames = array_map(static fn ($event): ?string => $event->event?->value, $fieldConflict->fieldEvents());
        $this->assertContains('field_overwritten', $eventNames);
        $this->assertNotContains('field_expired', $eventNames);

        $fieldOwner = $this->actor('field-owner', 53);
        $dragon = $this->actor('dragon', 62);
        $mixed = new BattleState($fieldOwner, $dragon, 'pvp');
        $this->applyCast($fieldOwner, $mixed, $this->attach($fieldOwner, $this->art(53, 1)));
        app(JobArtV2ResourceService::class)->beginAction($dragon, $mixed);
        $this->assertSame(100, app(JobArtV2FieldService::class)->modifyDamage($dragon, $mixed, 100, DamageSourceType::JOB_ART));
        $this->applyCast($dragon, $mixed, $this->attach($dragon, $this->art(62, 1)));
        app(JobArtV2PenetrationStanceService::class)->beginCast($dragon, $mixed, $this->art(62, 1));
        $this->assertTrue($dragon->hasPiercingStance());
        $this->assertSame('star_light', $mixed->primaryField()?->key);

        $melodyOwner = $this->actor('melody-owner', 85);
        $otherDragon = $this->actor('other-dragon', 62);
        $melodyState = new BattleState($melodyOwner, $otherDragon, 'pvp');
        $melody = $this->attach($melodyOwner, $this->art(85, 9));
        $melodyOwner->configureResource('star_mark', 12);
        $melodyOwner->setResource('star_mark', 12);
        $this->applyCast($melodyOwner, $melodyState, $melody);
        app(JobArtV2ResourceService::class)->beginAction($melodyOwner, $melodyState);
        $this->assertSame(53, app(JobArtV2FieldService::class)->activationRate($melodyOwner, $melodyState, 50));
        app(JobArtV2ResourceService::class)->beginAction($otherDragon, $melodyState);
        $this->assertSame(50, app(JobArtV2FieldService::class)->activationRate($otherDragon, $melodyState, 50));
        $this->assertSame(0.0, app(JobArtV2FieldService::class)->accuracyDelta($otherDragon, $melodyState));
    }

    public function test_hit_miss_and_evade_consume_the_same_cast_but_only_hit_applies_damage(): void
    {
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $expected) {
            $skill = $this->art(62, 5);
            [$actor, $target, $state] = $this->battle(62, [$skill]);
            $actor->configureResource('dragon_force', 12);
            $actor->setResource('dragon_force', 4);
            $actor->setPiercingStance(true);
            [$service] = $this->battleService(
                [1],
                [$expected === HitResult::MISS ? 100 : 1, 1],
                $expected === HitResult::EVADE ? 100.0 : 0.0,
            );
            $trace = new JobArtV2BattleTrace();
            $before = $trace->snapshot($actor, $target, $state);
            mt_srand(62_120);

            $service->actForTest($actor, $target, $state);
            $line = $trace->capture(1, $actor, $target, $state, $before, [$skill]);

            $this->assertSame(0, $actor->getResource('dragon_force'), $expected->value);
            $this->assertTrue($actor->hasPiercingStance(), $expected->value);
            $this->assertSame(1, $state->jobArtUseCounts[(int) $skill->id] ?? 0, $expected->value);
            $this->assertSame(387, $actor->mp, $expected->value);
            $this->assertStringContainsString("hit={$expected->value}", $line);
            if ($expected === HitResult::HIT) {
                $this->assertLessThan((int) $before['target_hp'], $target->hp);
            } else {
                $this->assertSame((int) $before['target_hp'], $target->hp);
            }
        }
    }

    public function test_flag_dependency_matrix_fails_closed_without_partial_state_mutation(): void
    {
        $gate = app(JobArtV2FeatureGate::class);
        [$actor, , $state] = $this->battle(62, [$this->art(62, 1)]);

        $this->assertTrue($gate->usesDynamicSingle($actor));
        $this->assertTrue($gate->usesPr5Rules($actor));
        $this->assertTrue($gate->usesResources($actor));
        $this->assertTrue($gate->usesPenetration($actor));
        $this->assertTrue($gate->usesPenetrationStance($actor));

        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $dependency) {
            $this->enableAllPrototypeFlags();
            config(["battle.job_art_v2.{$dependency}" => false]);
            [$caseActor, , $caseState] = $this->battle(62, [$this->art(62, 1)]);
            $before = serialize([$caseActor, $caseState]);

            $this->assertFalse($gate->usesResources($caseActor), $dependency);
            $this->assertFalse($gate->usesPenetration($caseActor), $dependency);
            $this->assertFalse($gate->usesPenetrationStance($caseActor), $dependency);
            $this->assertNull(app(JobArtV2ResourceService::class)->beginAction($caseActor, $caseState), $dependency);
            $this->assertSame($before, serialize([$caseActor, $caseState]), $dependency);
        }

        $this->enableAllPrototypeFlags();
        config(['battle.job_art_v2.penetration' => false]);
        $this->assertFalse($gate->usesPenetrationStance($actor));
        $this->enableAllPrototypeFlags();
        config(['battle.job_art_v2.penetration_stance' => false]);
        $this->assertTrue($gate->usesPenetration($actor));
        $this->assertFalse($gate->usesPenetrationStance($actor));

        $fieldActor = $this->actor('field', 53);
        $fieldState = new BattleState($fieldActor, $this->actor('other', 62));
        $this->enableAllPrototypeFlags();
        config(['battle.job_art_v2.resources' => false]);
        $this->assertFalse($gate->usesFields($fieldState));
        $this->assertNull($fieldState->primaryField());
    }

    public function test_all_ten_prototype_flags_are_declared_default_off(): void
    {
        $config = file_get_contents(base_path('config/battle.php'));
        $environment = file_get_contents(base_path('.env.example'));
        $flags = [
            'PVP_SET' => 'pvp_set',
            'LOADOUT_V2' => 'loadout_v2',
            'DYNAMIC_SINGLE' => 'dynamic_single',
            'NORMALIZED_SP' => 'normalized_sp',
            'HIT_RESOLUTION' => 'hit_resolution',
            'DAMAGE_APPLICATION' => 'damage_application',
            'RESOURCES' => 'resources',
            'FIELDS' => 'fields',
            'PENETRATION' => 'penetration',
            'PENETRATION_STANCE' => 'penetration_stance',
        ];

        foreach ($flags as $environmentKey => $configKey) {
            $this->assertStringContainsString(
                "'{$configKey}' => env('BATTLE_JOB_ART_{$environmentKey}', false)",
                $config,
                $configKey,
            );
            $this->assertStringContainsString("BATTLE_JOB_ART_{$environmentKey}=false", $environment, $environmentKey);
        }
    }

    public function test_sp_costs_and_conserve_boundary_match_the_frozen_vertical_slice(): void
    {
        $calculator = app(JobArtV2SpCostCalculator::class);
        foreach ([1 => [8, 10], 5 => [13, 16], 9 => [18, 22]] as $rank => [$current, $inherited]) {
            $skill = $this->art(53, $rank);
            $this->assertSame($current, $calculator->forCurrentJob($skill, 400, 53, 'current'));
            $this->assertSame($inherited, $calculator->forCurrentJob($skill, 400, 24, 'inherited'));
        }

        $skill = $this->art(53, 1);
        [$actor, , $state] = $this->battle(53, [$skill]);
        $actor->jobArtPolicies[(int) $skill->id] = 'conserve';
        $actor->mp = 160;
        [$selection] = $this->selection([1]);
        $this->assertSame($skill, $selection->selectForTurn($actor, $state)->skill);
        $actor->mp = 159;
        $this->assertSame('blocked_by_sp_or_policy', $selection->selectForTurn($actor, $state)->blockedReasons[(int) $skill->id]);
    }

    public function test_each_prototype_job_crosses_the_shared_slice_in_all_six_battle_contexts(): void
    {
        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach ([24, 53, 62, 85] as $jobId) {
                $rankOne = $this->art($jobId, 1);
                [$actor, $target, $state] = $this->battle($jobId, [$rankOne], $battleType);
                [$service] = $this->battleService([1], [1]);
                mt_srand(90_000 + $jobId);

                $service->actForTest($actor, $target, $state);

                $this->assertSame(1, $state->jobArtUseCounts[(int) $rankOne->id] ?? 0, "{$battleType}/{$jobId}");
                $this->assertSame(4, $actor->getResource($jobId === 62 ? 'dragon_force' : 'star_mark'), "{$battleType}/{$jobId}");
                $this->assertSame(392, $actor->mp, "{$battleType}/{$jobId}");
            }
        }
    }

    public function test_transmute_and_break_cross_the_shared_slice_in_all_six_battle_contexts(): void
    {
        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach ([67 => 'catalyst', 68 => 'break'] as $jobId => $resourceKey) {
                $rankOne = $this->art($jobId, 1);
                [$actor, $target, $state] = $this->battle($jobId, [$rankOne], $battleType);
                $actor->hp = $actor->maxHp;
                [$service] = $this->battleService([1], [1]);
                mt_srand(230_000 + ($jobId * 10));

                $service->actForTest($actor, $target, $state);

                $this->assertSame(1, $state->jobArtUseCounts[(int) $rankOne->id] ?? 0, "{$battleType}/{$jobId}");
                $this->assertSame(4, $actor->getResource($resourceKey), "{$battleType}/{$jobId}");
                if ($jobId === 67) {
                    $this->assertCount(1, $state->conversionResults(), $battleType);
                    $this->assertTrue($state->conversionResults()[0]->success, $battleType);
                    $this->assertSame(400, $actor->mp, $battleType);
                } else {
                    $this->assertSame(392, $actor->mp, $battleType);
                }
            }
        }
    }

    public function test_all_six_paths_keep_the_shared_vertical_slice_wiring(): void
    {
        $sources = [
            'pve/boss' => file_get_contents(base_path('app/Services/BattleService.php')),
            'tower' => file_get_contents(base_path('app/Services/TowerBattleService.php')),
            'pvp' => file_get_contents(base_path('app/Services/PvPBattleService.php')),
            'champ' => file_get_contents(base_path('app/Services/ChampBattleService.php')),
            'arena_npc' => file_get_contents(base_path('app/Services/ArenaNpcBattleService.php')),
        ];

        $this->assertStringContainsString("\$battleContext = \$enemy->is_boss ? 'boss' : 'pve';", $sources['pve/boss']);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $sources['tower']);
        foreach (['pvp', 'champ', 'arena_npc'] as $path) {
            $this->assertStringContainsString('selectForTurn(', $sources[$path], $path);
            $this->assertStringContainsString('completeJobArtCast(', $sources[$path], $path);
            $this->assertStringContainsString('endRound(', $sources[$path], $path);
        }
    }

    /** @return array{BattleService&object, JobArtV2RandomSource, JobArtV2HitRandomSource} */
    private function battleService(array $selectionRolls, array $hitRolls, float $evasionRate = 0.0): array
    {
        [$selection, $selectionRandom] = $this->selection($selectionRolls);
        $hitRandom = new class($hitRolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 1;
            }
        };
        $evasion = new class($evasionRate) extends JobArtV2ActiveEvasionProvider
        {
            public function __construct(private readonly float $rate) {}

            public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
            {
                return $this->rate;
            }
        };
        $resolver = new ActionResolver(
            app(JobArtV2FeatureGate::class),
            new DamageCalculator(),
            $hitRandom,
            $evasion,
            app(JobArtV2FieldService::class),
        );
        $service = new class(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(JobArtService::class),
            app(JobArtV2FeatureGate::class),
            $selection,
            app(JobArtV2SpCostCalculator::class),
            $resolver,
            null,
            app(JobArtV2ResourceService::class),
            app(JobArtV2FieldService::class),
            null,
            app(JobArtV2PenetrationStanceService::class),
        ) extends BattleService
        {
            public function actForTest(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->executeAction($attacker, $defender, $state);
            }
        };

        return [$service, $selectionRandom, $hitRandom];
    }

    /** @return array{JobArtV2SelectionService, JobArtV2RandomSource} */
    private function selection(array $rolls): array
    {
        $random = new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };

        return [new JobArtV2SelectionService(
            $random,
            app(JobArtV2FinisherConditionProvider::class),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
            app(JobArtV2ResourceService::class),
            app(JobArtV2FieldService::class),
        ), $random];
    }

    /** @param array<int, Skill> $arts */
    private function battle(
        int $jobId,
        array $arts,
        string $battleType = 'pve',
        string $origin = 'current',
    ): array {
        $actor = $this->actor('actor', $jobId);
        $target = $this->actor('target', null);
        $actor->jobArts = $arts;
        $actor->jobArtActivationPolicy = 'aggressive';
        foreach ($arts as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = $origin;
            $actor->jobArtRates[(int) $skill->id] = $origin === 'current' ? 1.0 : 0.7;
            $actor->jobArtPolicies[(int) $skill->id] = 'aggressive';
        }

        return [$actor, $target, new BattleState($actor, $target, $battleType)];
    }

    private function actor(string $name, ?int $jobId): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => $name === 'actor' ? 1_000 : 1_000_000,
            'max_hp' => 1_000_000,
            'mp' => 400,
            'max_mp' => 400,
            'str' => 1_000,
            'def' => 500,
            'agi' => 100,
            'mag' => 1_000,
            'spr' => 500,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function attach(BattleActor $actor, Skill $skill, string $origin = 'current'): Skill
    {
        $actor->jobArts[] = $skill;
        $actor->jobArtOrigins[(int) $skill->id] = $origin;
        $actor->jobArtRates[(int) $skill->id] = $origin === 'current' ? 1.0 : 0.7;
        $actor->jobArtPolicies[(int) $skill->id] = 'aggressive';

        return $skill;
    }

    private function applyCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $metadata = app(JobArtV2PrototypeCatalog::class)->artResourceMetadata($skill);
        if ($metadata !== null) {
            $key = (string) $metadata['resource_key'];
            $actor->configureResource($key, (int) $metadata['resource_max_points']);
            $minimum = (int) $metadata['minimum_resource_points'];
            if ($actor->getResource($key) < $minimum) {
                $actor->setResource($key, $minimum);
            }
        }
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $skill);
        app(JobArtV2PenetrationStanceService::class)->beginCast($actor, $state, $skill);
    }

    private function art(int $jobId, int $rank): Skill
    {
        $row = self::masterRows()[$jobId][$rank] ?? null;
        $this->assertNotNull($row, "Trusted master art {$jobId}/{$rank} must exist.");
        $skill = new Skill($row);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private static function masterRows(): array
    {
        if (self::$masterRows !== null) {
            return self::$masterRows;
        }

        $rows = json_decode((string) file_get_contents(base_path('database/data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
        self::$masterRows = [];
        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $rank = (int) ($row['learn_rank'] ?? 0);
            if (in_array($jobId, [24, 53, 62, 67, 68, 85], true) && in_array($rank, [1, 5, 9], true)) {
                self::$masterRows[$jobId][$rank] = $row;
            }
        }

        return self::$masterRows;
    }

    private function enableAllPrototypeFlags(): void
    {
        config([
            'battle.job_art_v2.pvp_set' => true,
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }
}
