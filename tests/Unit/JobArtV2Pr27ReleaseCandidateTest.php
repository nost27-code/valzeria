<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\FieldEvent;
use App\Services\FieldState;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2PowerResolver;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

class JobArtV2Pr27ReleaseCandidateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    public function test_job_63_remains_supported_with_only_its_frozen_metadata(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        $this->assertCount(94, $catalog->supportedCurrentJobs());
        $this->assertSame('crown', $catalog->currentJobTier(63));
        $this->assertSame('full_v2_effect', $catalog->effectCoverageForCurrentJob(63));
        $this->assertSame([
            'deploy',
            'next_cycle',
            2,
        ], [
            $catalog->artResourceMetadataForJobRank(63, 1)['field_operation'],
            $catalog->artResourceMetadataForJobRank(63, 1)['field_selection_mode'],
            $catalog->artResourceMetadataForJobRank(63, 1)['resource_gain_on_field_overwrite_points'],
        ]);
        $this->assertSame('echo_previous_overwritten', $catalog->artResourceMetadataForJobRank(63, 5)['field_operation']);
        $this->assertSame(1, $catalog->artResourceMetadataForJobRank(63, 5)['field_echo_rounds']);
        $rankNine = $catalog->artResourceMetadataForJobRank(63, 9);
        $this->assertSame('none', $rankNine['field_operation']);
        $this->assertSame([1.15, 1.30, 1.45], [
            $rankNine['field_overwrite_power_multiplier_1_2'],
            $rankNine['field_overwrite_power_multiplier_3_4'],
            $rankNine['field_overwrite_power_multiplier_5_plus'],
        ]);
    }

    public function test_rank_one_cycles_all_five_fields_and_only_actual_overwrite_adds_two(): void
    {
        [$actor, $state] = $this->battle(63);
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);
        $rankOne = $this->art(63, 1);

        $state->turnCount = 1;
        $resources->beginAction($actor, $state);
        $created = $resources->applyJobArtCast($actor, $state, $rankOne);
        $this->assertSame(4, $created->delta);
        $this->assertSame('star_light', $state->primaryField()?->key);
        $this->assertSame(0, $state->fieldOverwriteCountFor($actor));
        $this->assertSame(100, $fields->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART));

        $state->turnCount = 2;
        $resources->beginAction($actor, $state);
        $overwritten = $resources->applyJobArtCast($actor, $state, $rankOne);
        $this->assertSame(6, $overwritten->delta);
        $this->assertSame('melody', $state->primaryField()?->key);
        $this->assertSame(1, $state->fieldOverwriteCountFor($actor));
        $this->assertSame(110, $fields->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART));

        $lastResult = null;
        foreach (['sanctuary', 'silence', 'observation', 'star_light'] as $turn => $expectedKey) {
            $actor->setResource('star_mark', 0);
            $state->turnCount = $turn + 3;
            $resources->beginAction($actor, $state);
            $lastResult = $resources->applyJobArtCast($actor, $state, $rankOne);
            $this->assertSame($expectedKey, $state->primaryField()?->key);
        }
        $this->assertSame(7, $lastResult?->delta, 'observation modifies the combined 4+2 gain once');
        $this->assertSame(5, $state->fieldOverwriteCountFor($actor));
        $this->assertSame(5, count(array_filter(
            $state->fieldEvents(),
            static fn ($event): bool => $event->event === FieldEvent::OVERWRITTEN,
        )));
    }

    public function test_rank_one_locked_replacement_keeps_base_four_without_overwrite_bonus(): void
    {
        [$actor, $state] = $this->battle(63);
        $state->replacePrimaryField(new FieldState(
            'star_light',
            'enemy',
            3,
            999,
            999,
            0,
            overwriteLockRemainingRounds: 2,
            overwriteLockOwnerActorKey: 'enemy',
            overwriteLockCreatedRound: 0,
        ));
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $result = $resources->applyJobArtCast($actor, $state, $this->art(63, 1));

        $this->assertSame(4, $result->delta);
        $this->assertSame('star_light', $state->primaryField()?->key);
        $this->assertSame(0, $state->fieldOverwriteCountFor($actor));
        $this->assertTrue(collect($state->fieldEvents())->contains(
            static fn ($event): bool => $event->event === FieldEvent::OVERWRITE_BLOCKED,
        ));
    }

    public function test_rank_five_holds_the_displaced_owned_field_for_the_next_round_only(): void
    {
        [$actor, $state] = $this->battle(63);
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);
        $rankOne = $this->art(63, 1);
        $rankFive = $this->art(63, 5);

        $state->turnCount = 1;
        $resources->beginAction($actor, $state);
        $resources->applyJobArtCast($actor, $state, $rankOne);
        $state->turnCount = 2;
        $resources->beginAction($actor, $state);
        $resources->applyJobArtCast($actor, $state, $rankOne);
        $this->assertSame('star_light', $state->lastOverwrittenFieldFor($actor)?->key);

        $actor->setResource('star_mark', 4);
        $resources->beginAction($actor, $state);
        $spent = $resources->applyJobArtCast($actor, $state, $rankFive);
        $this->assertSame(-4, $spent->delta);
        $this->assertSame('star_light', $state->fieldEchoFor($actor)?->key);
        $this->assertSame(1, $state->fieldEchoFor($actor)?->remainingRounds);

        $fields->endRound($state);
        $this->assertNotNull($state->fieldEchoFor($actor));
        $state->turnCount = 3;
        $resources->beginAction($actor, $state);
        $fields->markSkillAction($actor, $state, $this->art(63, 9));
        $this->assertSame(110, $fields->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART));
        $fields->endRound($state);
        $this->assertNull($state->fieldEchoFor($actor));
    }

    public function test_job_63_field_actions_remain_same_lineage_only_but_cross_lineage_resource_is_generated(): void
    {
        $resources = app(JobArtV2ResourceService::class);
        $inheritedRankOne = $this->art(63, 1);

        [$fieldActor, $fieldState] = $this->battle(53);
        $fieldActor->jobArtOrigins[(int) $inheritedRankOne->id] = 'inherited';
        $resources->beginAction($fieldActor, $fieldState);
        $resources->applyJobArtCast($fieldActor, $fieldState, $inheritedRankOne);
        $this->assertSame('star_light', $fieldState->primaryField()?->key);
        $this->assertSame(4, $fieldActor->getResource('star_mark'));

        [$foreignActor, $foreignState] = $this->battle(62);
        $foreignActor->jobArtOrigins[(int) $inheritedRankOne->id] = 'inherited';
        $resources->beginAction($foreignActor, $foreignState);
        $result = $resources->applyJobArtCast($foreignActor, $foreignState, $inheritedRankOne);
        $this->assertTrue($result->applied);
        $this->assertSame(4, $result->delta);
        $this->assertNull($foreignState->primaryField());
        $this->assertSame(0, $foreignActor->getResource('dragon_force'));
        $this->assertSame(4, $foreignActor->getResource('star_mark'));
    }

    public function test_same_lineage_inherited_rank_five_echoes_the_owners_field_after_an_opponent_overwrites_it(): void
    {
        [$actor, $state] = $this->battle(53);
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);
        $rankOne = $this->art(63, 1);
        $rankFive = $this->art(63, 5);
        $actor->jobArtOrigins[(int) $rankOne->id] = 'inherited';
        $actor->jobArtOrigins[(int) $rankFive->id] = 'inherited';

        $resources->beginAction($actor, $state);
        $resources->applyJobArtCast($actor, $state, $rankOne);
        $this->assertSame('star_light', $state->primaryField()?->key);

        $state->turnCount = 1;
        $fields->deployPrimary($state->enemy, $state, 'melody', 999, 999);
        $this->assertSame('star_light', $state->lastOverwrittenFieldFor($actor)?->key);

        $actor->setResource('star_mark', 4);
        $resources->beginAction($actor, $state);
        $spent = $resources->applyJobArtCast($actor, $state, $rankFive);

        $this->assertSame(-4, $spent->delta);
        $this->assertSame('star_light', $state->fieldEchoFor($actor)?->key);
        $this->assertSame('melody', $state->primaryField()?->key);
    }

    public function test_rank_nine_uses_the_frozen_overwrite_power_boundaries_with_a_primary_field(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        foreach ([0 => 1.00, 1 => 1.15, 2 => 1.15, 3 => 1.30, 4 => 1.30, 5 => 1.45, 8 => 1.45] as $count => $expectedMultiplier) {
            [$actor, $state] = $this->battle(63);
            $rankNine = $this->art(63, 9);
            $actor->jobArtOrigins[(int) $rankNine->id] = 'current';
            $this->createActualOverwrites($actor, $state, $count);
            app(JobArtV2ResourceService::class)->beginAction($actor, $state);

            $branch = $resolver->fieldOverwriteBranchForExecution($actor, $rankNine, $state);
            $this->assertSame($count, $branch['overwrite_count'], "count {$count}");
            $this->assertTrue($branch['primary_field_present'], "count {$count}");
            $this->assertSame($expectedMultiplier, $branch['multiplier'], "count {$count}");
            $this->assertSame(100, $resolver->forExecution($actor, $rankNine, $state), "count {$count}");
            $this->assertLessThanOrEqual(1.45, $branch['multiplier'], "count {$count}");
        }
    }

    public function test_non_overwrite_field_events_never_raise_the_counter(): void
    {
        [$actor, $state] = $this->battle(63);
        $fields = app(JobArtV2FieldService::class);

        $fields->deployPrimary($actor, $state, 'star_light', 631, 1);
        $fields->deployPrimary($actor, $state, 'star_light', 631, 2);
        $fields->extendPrimary($actor, $state, 635, 3);
        $fields->createOverlay($actor, $state, 'melody', 639, 4);
        foreach (range(1, 4) as $turn) {
            $state->turnCount = $turn;
            $fields->endRound($state);
        }

        $this->assertSame(0, $state->fieldOverwriteCountFor($actor));
        $events = array_map(static fn ($event): ?FieldEvent => $event->event, $state->fieldEvents());
        $this->assertContains(FieldEvent::CREATED, $events);
        $this->assertContains(FieldEvent::REFRESHED, $events);
        $this->assertContains(FieldEvent::EXTENDED, $events);
        $this->assertContains(FieldEvent::EXPIRED, $events);
        $this->assertContains(FieldEvent::OVERLAY_CREATED, $events);
        $this->assertContains(FieldEvent::OVERLAY_EXPIRED, $events);
        $this->assertNotContains(FieldEvent::OVERWRITTEN, $events);
    }

    public function test_rank_nine_uses_action_start_primary_snapshot_and_falls_back_without_it(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);
        $resources = app(JobArtV2ResourceService::class);

        [$withoutField, $withoutFieldState] = $this->battle(63);
        $rankNine = $this->art(63, 9);
        $withoutField->jobArtOrigins[(int) $rankNine->id] = 'current';
        $this->createActualOverwrites($withoutField, $withoutFieldState, 3);
        $withoutFieldState->replacePrimaryField(null);
        $resources->beginAction($withoutField, $withoutFieldState);
        app(JobArtV2FieldService::class)->deployPrimary($withoutField, $withoutFieldState, 'star_light', 631, 90);
        $this->assertSame(3, $withoutFieldState->fieldOverwriteCountFor($withoutField));
        $this->assertSame(100, $resolver->forExecution($withoutField, $rankNine, $withoutFieldState));

        [$withField, $withFieldState] = $this->battle(63);
        $withField->jobArtOrigins[(int) $rankNine->id] = 'current';
        $this->createActualOverwrites($withField, $withFieldState, 3);
        $resources->beginAction($withField, $withFieldState);
        $withFieldState->replacePrimaryField(null);
        $this->assertSame(1.30, $resolver->fieldOverwriteBranchForExecution($withField, $rankNine, $withFieldState)['multiplier']);
        $this->assertSame(100, $resolver->forExecution($withField, $rankNine, $withFieldState));
    }

    public function test_rank_nine_branch_and_source_resource_work_for_every_equipped_lineage(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);
        $resources = app(JobArtV2ResourceService::class);
        $rankNine = $this->art(63, 9);

        [$sameLineage, $sameLineageState] = $this->battle(53);
        $sameLineage->jobArts = [$rankNine];
        $sameLineage->jobArtOrigins[(int) $rankNine->id] = 'inherited';
        $sameLineage->configureResource('star_mark', 12);
        $sameLineage->setResource('star_mark', 12);
        $this->createActualOverwrites($sameLineage, $sameLineageState, 5);
        $resources->beginAction($sameLineage, $sameLineageState);
        $this->assertSame(1.45, $resolver->fieldOverwriteBranchForExecution($sameLineage, $rankNine, $sameLineageState)['multiplier']);
        $this->assertSame(100, $resolver->forExecution($sameLineage, $rankNine, $sameLineageState));
        $this->assertSame(-12, $resources->applyJobArtCast($sameLineage, $sameLineageState, $rankNine)->delta);

        [$crossLineage, $crossLineageState] = $this->battle(62);
        $crossLineage->jobArts = [$rankNine];
        $crossLineage->jobArtOrigins[(int) $rankNine->id] = 'inherited';
        $crossLineage->configureResource('dragon_force', 12);
        $crossLineage->setResource('dragon_force', 12);
        $crossLineage->configureResource('star_mark', 12);
        $crossLineage->setResource('star_mark', 12);
        $this->createActualOverwrites($crossLineage, $crossLineageState, 3);
        $resources->beginAction($crossLineage, $crossLineageState);
        $this->assertSame(1.30, $resolver->fieldOverwriteBranchForExecution($crossLineage, $rankNine, $crossLineageState)['multiplier']);
        $this->assertSame(100, $resolver->forExecution($crossLineage, $rankNine, $crossLineageState));
        $this->assertSame(-12, $resources->applyJobArtCast($crossLineage, $crossLineageState, $rankNine)->delta);
        $this->assertSame(12, $crossLineage->getResource('dragon_force'));
        $this->assertSame(0, $crossLineage->getResource('star_mark'));
    }

    public function test_rank_nine_spends_twelve_is_prioritized_records_hud_and_ignores_legacy_use_cap(): void
    {
        [$actor, $state] = $this->battle(63);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);
        $rankNine = $this->art(63, 9);
        $rankOne = $this->art(63, 1);
        $actor->jobArts = [$rankOne, $rankNine];
        $actor->jobArtActivationPolicy = 'aggressive';
        $actor->jobArtOrigins[(int) $rankOne->id] = 'current';
        $actor->jobArtOrigins[(int) $rankNine->id] = 'current';
        $this->createActualOverwrites($actor, $state, 4);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $selection = $this->selection([1]);
        $selected = $selection->selectForTurn($actor, $state);
        $this->assertSame($rankNine->id, $selected->skill?->id);
        $this->assertTrue($selected->rankNinePrioritized);
        $result = $resources->applyJobArtCast($actor, $state, $rankNine);
        app(JobArtV2BattleHudService::class)->recordHitResult($actor, $state, HitResult::HIT);
        app(JobArtV2BattleHudService::class)->finishAction($actor, $state);

        $this->assertSame(-12, $result->delta);
        $this->assertSame(0, $actor->getResource('star_mark'));
        $this->assertNotNull($state->primaryField());
        $hudAction = app(JobArtV2BattleHudService::class)->present($state)['actions'][0];
        $this->assertSame(4, $hudAction['field_overwrite_power']['overwrite_count']);
        $this->assertSame(1.30, $hudAction['field_overwrite_power']['multiplier']);
        $this->assertSame(30, $hudAction['field_overwrite_power']['bonus_percent']);

        $state->jobArtUseCounts[(int) $rankNine->id] = 1;
        $actor->setResource('star_mark', 12);
        $this->assertNull($selection->eligibilityFailureReason($actor, $state, $rankNine, (int) $rankNine->id));
    }

    public function test_rank_nine_branch_keeps_hit_results_flags_and_all_six_battle_contexts_unchanged(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            [$actor, $state] = $this->battle(63, $battleType);
            $rankNine = $this->art(63, 9);
            $actor->jobArts = [$rankNine];
            $actor->jobArtOrigins[(int) $rankNine->id] = 'current';
            $this->createActualOverwrites($actor, $state, 3);
            app(JobArtV2ResourceService::class)->beginAction($actor, $state);

            $this->assertSame(100, $resolver->forExecution($actor, $rankNine, $state), $battleType);
            $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $rankNine, $state, $state->enemy);
            $this->assertSame(100, (int) $execution->power, $battleType);
            $this->assertSame(1.30, (float) $execution->getAttribute('job_art_v2_target_damage_multiplier'), $battleType);
        }

        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $state] = $this->battle(63);
            $rankNine = $this->art(63, 9);
            $actor->jobArts = [$rankNine];
            $actor->jobArtOrigins[(int) $rankNine->id] = 'current';
            $actor->configureResource('star_mark', 12);
            $actor->setResource('star_mark', 12);
            $this->createActualOverwrites($actor, $state, 3);
            app(JobArtV2ResourceService::class)->beginAction($actor, $state);
            $this->assertSame(100, $resolver->forExecution($actor, $rankNine, $state));
            app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $rankNine);
            app(JobArtV2BattleHudService::class)->recordHitResult($actor, $state, $hitResult);
            app(JobArtV2BattleHudService::class)->finishAction($actor, $state);
            $actions = app(JobArtV2BattleHudService::class)->present($state)['actions'];
            $this->assertNotEmpty($actions, $hitResult->value);
            $this->assertSame($hitResult->value, $actions[0]['hit_result']);
            $this->assertSame(0, $actor->getResource('star_mark'));
        }

        [$legacyActor, $legacyState] = $this->battle(63);
        $rankNine = $this->art(63, 9);
        $legacyActor->jobArtOrigins[(int) $rankNine->id] = 'current';
        $this->createActualOverwrites($legacyActor, $legacyState, 5);
        app(JobArtV2ResourceService::class)->beginAction($legacyActor, $legacyState);
        config(['battle.job_art_v2.fields' => false]);
        $this->assertSame(100, $resolver->forExecution($legacyActor, $rankNine, $legacyState));
    }

    public function test_rank_nine_loadout_copy_uses_only_catalog_backed_frozen_values(): void
    {
        $rankNine = $this->art(63, 9);
        $rankNine->setAttribute('job_art_origin', 'current');
        $presented = app(JobArtV2LoadoutPresenter::class)->forArt(63, $rankNine);

        $this->assertSame([
            'この奥義の行動開始時に自分の主場がある場合、この戦闘中に自分が場を上書きした回数が1～2回なら威力を15%、3～4回なら30%、5回以上なら45%上げる',
        ], $presented['field_texts']);
        $this->assertSame(100, $presented['effective_power']);

        $rankNine->setAttribute('job_art_origin', 'inherited');
        $inherited = app(JobArtV2LoadoutPresenter::class)->forArt(53, $rankNine);
        $this->assertSame($presented['field_texts'], $inherited['field_texts']);
        $this->assertSame(100, $inherited['effective_power']);
    }

    public function test_job_63_uses_the_same_field_resource_contract_in_all_six_battle_contexts(): void
    {
        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            [$actor, $state] = $this->battle(63, $battleType);
            $resources = app(JobArtV2ResourceService::class);

            $resources->beginAction($actor, $state);
            $result = $resources->applyJobArtCast($actor, $state, $this->art(63, 1));

            $this->assertSame(4, $result->delta, $battleType);
            $this->assertSame('star_light', $state->primaryField()?->key, $battleType);
        }
    }

    /** @return array{BattleActor, BattleState} */
    private function battle(int $currentJobId, string $battleType = 'pve'): array
    {
        $actor = new BattleActor('actor', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'mag' => 100,
            'agi' => 100,
            'current_job_id' => $currentJobId,
        ]);
        $enemy = new BattleActor('enemy', false, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'mag' => 100,
            'agi' => 100,
        ]);

        return [$actor, new BattleState($actor, $enemy, $battleType)];
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
            'effect_template' => 'MAGICAL_DAMAGE',
            'power' => 100,
            'power_multiplier' => 1.0,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }

    private function createActualOverwrites(BattleActor $actor, BattleState $state, int $count): void
    {
        $fields = app(JobArtV2FieldService::class);
        $fields->deployPrimary($actor, $state, 'star_light', 631, 10_000);
        $fieldKey = 'star_light';
        for ($index = 1; $index <= $count; $index++) {
            $fieldKey = $fieldKey === 'star_light' ? 'melody' : 'star_light';
            $fields->deployPrimary($actor, $state, $fieldKey, 631, 10_000 + $index);
        }

        $this->assertSame($count, $state->fieldOverwriteCountFor($actor));
    }

    /** @param list<int> $rolls */
    private function selection(array $rolls): JobArtV2SelectionService
    {
        $random = new class($rolls) extends JobArtV2RandomSource
        {
            private int $calls = 0;

            /** @param list<int> $rolls */
            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };

        return new JobArtV2SelectionService(
            $random,
            app(JobArtV2FinisherConditionProvider::class),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
            app(JobArtV2ResourceService::class),
        );
    }
}
