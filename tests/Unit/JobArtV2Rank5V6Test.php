<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtService;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2ParryRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2Rank5CycleState;
use App\Services\JobArtV2Rank5V6Catalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2TimedEffectState;
use Tests\TestCase;

class JobArtV2Rank5V6Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.rank5_v6' => true,
        ]);
    }

    protected function tearDown(): void
    {
        config(['battle.job_art_v2.rank5_v6' => false]);

        parent::tearDown();
    }

    public function test_feature_flag_is_an_additional_fail_closed_boundary(): void
    {
        $actor = $this->actor(1);
        $gate = app(JobArtV2FeatureGate::class);

        $this->assertTrue($gate->usesRank5V6($actor));

        config(['battle.job_art_v2.rank5_v6' => false]);
        $this->assertFalse($gate->usesRank5V6($actor));

        config(['battle.job_art_v2.rank5_v6' => true, 'battle.job_art_v2.resources' => false]);
        $this->assertFalse($gate->usesRank5V6($actor));
    }

    public function test_flag_off_preserves_an_unmigrated_rank_five_skill(): void
    {
        config(['battle.job_art_v2.rank5_v6' => false]);

        $legacy = $this->art(3005, 30, 5, '暗黒剣');
        $legacy->power = 185;
        $legacy->power_multiplier = 1.85;

        $execution = app(JobArtV2CrownBalanceCatalog::class)->applyToExecution($legacy);

        $this->assertSame(185, (int) $execution->power);
        $this->assertSame(1.85, (float) $execution->power_multiplier);
    }

    public function test_catalog_and_versioned_migration_data_cover_exactly_94_rank_five_cards(): void
    {
        $catalog = app(JobArtV2Rank5V6Catalog::class);
        $this->assertCount(94, $catalog->all());
        $this->assertSame([15, 28, 48, 49, 54, 64, 85, 93], array_values(array_map(
            'intval',
            array_keys(array_filter($catalog->all(), static fn (array $row): bool => $row['trigger_mode'] === 'reactive')),
        )));

        $payload = json_decode(
            (string) file_get_contents(database_path('data/job_art_rank5_v6_1_migration.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(94, $payload['old']);
        $this->assertCount(94, $payload['new']);
        $jobIds = array_keys($catalog->all());
        sort($jobIds);
        $this->assertSame(array_values(array_diff(range(1, 99), [39, 40, 41, 42, 43])), $jobIds);
        $catalogKeys = array_map(static fn (int $jobId): string => $jobId.':5', array_keys($catalog->all()));
        $migrationKeys = array_keys($payload['new']);
        sort($catalogKeys);
        sort($migrationKeys);
        $this->assertSame($catalogKeys, $migrationKeys);
    }

    public function test_master_json_and_migration_new_values_match_the_94_card_catalog(): void
    {
        $catalog = app(JobArtV2Rank5V6Catalog::class);
        $masterRows = json_decode(
            (string) file_get_contents(database_path('data/job_arts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $rankFive = [];
        foreach ($masterRows as $row) {
            if ((int) $row['learn_rank'] === 5) {
                $rankFive[(int) $row['job_id']] = $row;
            }
        }
        $this->assertCount(94, $rankFive);

        $migration = json_decode(
            (string) file_get_contents(database_path('data/job_art_rank5_v6_1_migration.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($catalog->all() as $jobId => $spec) {
            $row = $rankFive[$jobId];
            $this->assertSame($spec['name'], $row['name'], "job {$jobId} name");
            $this->assertSame($spec['power'] ?? 0, (int) $row['power_hint'], "job {$jobId} power");
            $this->assertSame($spec['effect_text'], $row['memo'], "job {$jobId} memo");

            foreach ($migration['new'][$jobId.':5'] as $column => $value) {
                $masterColumn = $column === 'power' ? 'power_hint' : ($column === 'description' ? 'memo' : $column);
                if ($column === 'power_multiplier') {
                    $this->assertEqualsWithDelta(((int) $row['power_hint']) / 100, (float) $value, 0.000001, "job {$jobId} multiplier");
                } else {
                    $this->assertSame($row[$masterColumn], $value, "job {$jobId} {$column}");
                }
            }
        }
    }

    public function test_rank_five_profile_keeps_resource_and_uses_fixed_minimum_without_consumption(): void
    {
        $prototype = app(JobArtV2PrototypeCatalog::class);

        $first = $prototype->artResourceMetadataForJobRank(1, 5);
        $this->assertSame(0, $first['resource_cost_points']);
        $this->assertSame(4, $first['minimum_resource_points']);
        $this->assertSame('scheduled', $first['link_trigger_mode']);

        $job55 = $prototype->artResourceMetadataForJobRank(55, 5);
        $this->assertSame(0, $job55['resource_cost_points']);
        $this->assertSame(8, $job55['minimum_resource_points']);

        $reactive = $prototype->artResourceMetadataForJobRank(15, 5);
        $this->assertSame('reactive', $reactive['link_trigger_mode']);

        config(['battle.job_art_v2.rank5_v6' => false]);
        $legacy = $prototype->artResourceMetadataForJobRank(1, 5);
        $this->assertSame(4, $legacy['resource_cost_points']);
        $this->assertArrayNotHasKey('link_trigger_mode', $legacy);
    }

    public function test_failed_first_slot_is_used_and_does_not_compress_second_slot_threshold(): void
    {
        $first = $this->art(105, 1, 5, '受け返し');
        $second = $this->art(1105, 11, 5, '居合斬り');
        [$actor, $state] = $this->battle(11, [$first, $second], 'sword_momentum', 4);
        $service = $this->selection([100, 1]);

        $failed = $service->selectForTurn($actor, $state);
        $this->assertFalse($failed->activated);
        $this->assertTrue($actor->jobArtV2Rank5CycleState()->hasUsed('sword_momentum', 105));

        $next = $service->selectForTurn($actor, $state);
        $this->assertNull($next->candidateSkillId);
        $this->assertSame('blocked_by_rank5_cycle', $next->blockedReasons[105]);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $next->blockedReasons[1105]);

        $actor->setResource('sword_momentum', 8);
        $ready = $service->selectForTurn($actor, $state);
        $this->assertSame(1105, $ready->candidateSkillId);
    }

    public function test_rank_nine_clears_only_the_same_resource_cycle_after_commit(): void
    {
        $rankFive = $this->art(105, 1, 5, '受け返し');
        $counterUltimate = $this->art(109, 1, 9, '剣神一閃');
        $huntUltimate = $this->art(309, 3, 9, '狩猟の完成');
        [$actor, $state] = $this->battle(1, [$rankFive, $counterUltimate], 'sword_momentum', 12);
        $actor->configureResource('hunt', 12);
        $actor->setResource('hunt', 12);
        $cycle = $actor->jobArtV2Rank5CycleState();
        $cycle->markUsed('sword_momentum', 105);
        $cycle->markUsed('hunt', 305);

        $this->beginAction($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $huntUltimate);
        $this->assertTrue($cycle->hasUsed('sword_momentum', 105));
        $this->assertFalse($cycle->hasUsed('hunt', 305));

        $cycle->markUsed('hunt', 305);
        $this->beginAction($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $counterUltimate);
        $this->assertFalse($cycle->hasUsed('sword_momentum', 105));
        $this->assertTrue($cycle->hasUsed('hunt', 305));
    }

    public function test_cycle_state_is_battle_memory_only_and_partitioned_by_resource(): void
    {
        $state = new JobArtV2Rank5CycleState();
        $state->markUsed('sword_momentum', 1);
        $state->markUsed('hunt', 3);
        $state->clearResource('sword_momentum');

        $this->assertFalse($state->hasUsed('sword_momentum', 1));
        $this->assertTrue($state->hasUsed('hunt', 3));
        $this->assertSame([3], $state->usedSkillIds('hunt'));
        $this->assertSame([], (new JobArtV2Rank5CycleState())->usedSkillIds('hunt'));
    }

    public function test_runtime_execution_uses_normalized_power_and_attackless_shape(): void
    {
        $balances = app(JobArtV2CrownBalanceCatalog::class);

        $counter = $balances->applyToExecution($this->art(105, 1, 5, '受け返し'));
        $this->assertSame(100, (int) $counter->power);
        $this->assertSame(1.0, (float) $counter->power_multiplier);
        $conditional = app(JobArtV2RoleEffectCatalog::class)->forArt($this->art(105, 1, 5, '受け返し'));
        $this->assertSame(1.35, $conditional['conditional_damage_multiplier']['multiplier']);

        $sageGuard = $balances->applyToExecution($this->art(2905, 29, 5, '賢者の結界'));
        $this->assertSame(100, (int) $sageGuard->power);
        $this->assertSame('MAGICAL_DAMAGE', (string) $sageGuard->effect_template);
        $this->assertSame(1, (int) $sageGuard->hit_count);
        $this->assertSame('magical', (string) $sageGuard->damage_type);

        $attackless = [
            7 => ['癒しの祈り', 'HEAL', 150],
            12 => ['勝利の采配', 'V2_ROLE_EFFECT_ONLY', 0],
            23 => ['勇気の旋律', 'SELF_BUFF', 0],
            25 => ['秘薬調合', 'HEAL_CLEANSE', 110],
            38 => ['王者の秘薬', 'HEAL', 110],
            47 => ['霊薬の加護', 'REWARD_MIXED', 0],
        ];

        foreach ($attackless as $jobId => [$name, $template, $expectedPower]) {
            $support = $balances->applyToExecution($this->art(
                ($jobId * 100) + 5,
                $jobId,
                5,
                $name,
                $template,
                'support',
            ));
            $this->assertSame($expectedPower, (int) $support->power, "job {$jobId}");
            $this->assertSame((float) $expectedPower / 100, (float) $support->power_multiplier, "job {$jobId}");
            $this->assertSame(0, (int) $support->hit_count, "job {$jobId}");
            $this->assertSame('support', (string) $support->damage_type, "job {$jobId}");
        }
    }

    public function test_v6_master_rows_and_runtime_never_fall_back_to_removed_rank_five_self_buffs(): void
    {
        $expected = [
            11 => ['PHYSICAL_DAMAGE', 1, 'physical'],
            12 => ['V2_ROLE_EFFECT_ONLY', 0, 'support'],
            29 => ['MAGICAL_DAMAGE', 1, 'magical'],
            44 => ['PHYSICAL_DAMAGE', 1, 'physical'],
            46 => ['MAGICAL_DAMAGE', 1, 'magical'],
            50 => ['PHYSICAL_DAMAGE', 1, 'physical'],
        ];
        $balances = app(JobArtV2CrownBalanceCatalog::class);

        foreach ($expected as $jobId => [$template, $hits, $damageType]) {
            $master = $this->masterArt($jobId);
            $this->assertSame($template, (string) $master->effect_template, "job {$jobId} master template");

            $execution = $balances->applyToExecution($master);
            $this->assertSame($template, (string) $execution->effect_template, "job {$jobId} runtime template");
            $this->assertSame($hits, (int) $execution->hit_count, "job {$jobId} hit count");
            $this->assertSame($damageType, (string) $execution->damage_type, "job {$jobId} damage type");
        }
    }

    public function test_redefined_rank_five_actions_do_not_apply_removed_permanent_stat_buffs(): void
    {
        $method = new \ReflectionMethod(BattleService::class, 'executeJobArtAction');

        foreach ([11, 12, 44, 46, 50] as $jobId) {
            $skill = $this->masterArt($jobId);
            $skill->setAttribute('sure_hit', true);
            $resourceKey = app(JobArtV2PrototypeCatalog::class)
                ->artResourceMetadataForJobRank($jobId, 5)['resource_key'];
            [$actor, $state] = $this->battle($jobId, [$skill], $resourceKey, 12);
            foreach (['str' => 200, 'def' => 100, 'mag' => 200, 'spr' => 100] as $stat => $value) {
                $actor->{$stat} = $value;
                $actor->{'base'.ucfirst($stat)} = $value;
            }
            $before = [$actor->str, $actor->def, $actor->mag, $actor->spr];

            $this->beginAction($actor, $state);
            $method->invoke(app(BattleService::class), $actor, $state->enemy, $state, $skill);

            $this->assertSame($before, [$actor->str, $actor->def, $actor->mag, $actor->spr], "job {$jobId}");
        }
    }

    public function test_all_six_attackless_rank_five_cards_leave_the_target_hp_unchanged(): void
    {
        $method = new \ReflectionMethod(BattleService::class, 'executeJobArtAction');
        $calculator = \Mockery::mock(DamageCalculator::class);
        $calculator->shouldNotReceive('calculatePhysicalDamage');
        $calculator->shouldNotReceive('calculateMagicalDamage');
        $service = new BattleService(
            app(CharacterStatusService::class),
            $calculator,
            app(JobArtService::class),
        );

        foreach ([7, 12, 23, 25, 38, 47] as $jobId) {
            $skill = $this->masterArt($jobId);
            $resourceKey = app(JobArtV2PrototypeCatalog::class)
                ->artResourceMetadataForJobRank($jobId, 5)['resource_key'];
            [$actor, $state] = $this->battle($jobId, [$skill], $resourceKey, 12);
            $beforeHp = $state->enemy->hp;

            $this->beginAction($actor, $state);
            $method->invoke($service, $actor, $state->enemy, $state, $skill);

            $this->assertSame($beforeHp, $state->enemy->hp, "job {$jobId}");
        }
    }

    public function test_jobs7_and10_apply_one_20_percent_guard_until_the_next_own_action(): void
    {
        $defense = app(JobArtV2DefenseService::class);

        foreach ([7, 10] as $jobId) {
            $skill = $this->masterArt($jobId);
            [$actor, $state] = $this->battle($jobId, [$skill], 'holy_guard', 4);
            $metadata = app(JobArtV2PrototypeCatalog::class)->artResourceMetadataForJobRank($jobId, 5);
            $this->assertSame(0.20, $metadata['guard_rate'], "job {$jobId}");
            $this->assertTrue($metadata['guard_expires_next_own_action'], "job {$jobId}");

            $this->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);
            $this->assertSame(0.20, $actor->jobArtV2GuardState()?->rate, "job {$jobId}");

            app(JobArtV2ResourceService::class)->beginAction($state->enemy, $state);
            $resolution = new DirectAttackResolution(
                $state->currentSourceActionId(),
                $state->enemy,
                $actor,
                HitResult::HIT,
                'physical',
                true,
                BattleActionType::NORMAL_ATTACK,
            );
            $this->assertSame(80, $defense->resolveDamage($state, $resolution, 100), "job {$jobId}");
            $this->assertNull($actor->jobArtV2GuardState(), "job {$jobId} consumed");

            $this->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);
            $this->assertNotNull($actor->jobArtV2GuardState(), "job {$jobId} rearmed");
            $this->beginAction($actor, $state);
            $this->assertNull($actor->jobArtV2GuardState(), "job {$jobId} expired");
        }
    }

    public function test_job47_uses_one_role_effect_path_for_healing_sp_and_rewards(): void
    {
        $source = $this->masterArt(47);
        [$actor, $state] = $this->battle(47, [$source], 'catalyst', 4);
        $actor->hp = 100;
        $actor->maxHp = 1000;
        $actor->mp = 0;
        $actor->maxMp = 1000;
        $actor->spr = 100;
        $actor->baseSpr = 100;
        $roleEffects = app(JobArtV2RoleEffectService::class);
        $execution = app(JobArtV2CrownBalanceCatalog::class)->applyToExecution($source);

        $this->assertSame('REWARD_MIXED', (string) $source->effect_template);
        $this->assertTrue($roleEffects->supportEffectCanBeMeaningful($actor, $source));

        $this->beginAction($actor, $state);
        $roleEffects->applyForExecution($actor, $state->enemy, $state, $source, $execution);

        $this->assertSame('V2_ROLE_EFFECT_ONLY', (string) $execution->effect_template);
        $this->assertSame('support', (string) $execution->damage_type);
        $this->assertSame(0, (int) $execution->mp_recover_percent);
        $this->assertSame(0, (int) $execution->gold_bonus_percent);
        $this->assertSame(0, (int) $execution->drop_bonus_percent);
        $this->assertSame(0, (int) $execution->rare_bonus_percent);
        $this->assertSame(10, $state->goldBonusPercent);
        $this->assertSame(8, $state->dropBonusPercent);
        $this->assertSame(5, $state->rareBonusPercent);

        $roleEffects->completeJobArtCast($actor, $state->enemy, $state, $source, null);
        $this->assertSame(220, $actor->hp);
        $this->assertSame(80, $actor->mp);
        $this->assertCount(1, array_filter(
            $state->logs,
            static fn (string $log): bool => str_contains($log, '探索勝利時の報酬判定'),
        ));

        $roleEffects->completeJobArtCast($actor, $state->enemy, $state, $source, null);
        $this->assertSame(220, $actor->hp);
        $this->assertSame(80, $actor->mp);

        $actor->hp = $actor->maxHp;
        $actor->mp = $actor->maxMp;
        $this->assertTrue($roleEffects->supportEffectCanBeMeaningful($actor, $source));
        $this->assertNull(app(JobArtV2CrownBalanceCatalog::class)->healSprPercent($source));
    }

    public function test_job47_presenter_expands_nested_spr_heal_metadata(): void
    {
        $skill = $this->masterArt(47);
        $skill->setAttribute('job_art_origin', 'current');

        $display = app(JobArtV2LoadoutPresenter::class)->forArt(47, $skill, [$skill]);

        $this->assertNotNull($display);
        $this->assertContains('精神の120%分、自分のHPを回復する', $display['effect_texts']);
        $this->assertNotContains('基礎回復量の120%を回復', $display['effect_texts']);
        $this->assertStringContainsString('レア素材枠の抽選率を5ポイント', $display['card_description']);
    }

    public function test_job47_remains_selectable_when_recovery_is_not_needed_because_rewards_are_meaningful(): void
    {
        foreach ([[1, 1000], [100, 1000]] as [$hp, $mp]) {
            $skill = $this->masterArt(47);
            [$actor, $state] = $this->battle(47, [$skill], 'catalyst', 4);
            $actor->hp = $hp;
            $actor->mp = $mp;

            $selection = $this->selection([1])->selectForTurn($actor, $state);

            $this->assertSame((int) $skill->id, $selection->candidateSkillId, "HP {$hp} / SP {$mp}");
            $this->assertTrue($selection->activated, "HP {$hp} / SP {$mp}");
            $this->assertArrayNotHasKey((int) $skill->id, $selection->blockedReasons, "HP {$hp} / SP {$mp}");
        }
    }

    public function test_job29_presenter_uses_the_rank5_v6_power_instead_of_the_legacy_role_power(): void
    {
        $skill = $this->masterArt(29);
        $skill->setAttribute('job_art_origin', 'current');

        $display = app(JobArtV2LoadoutPresenter::class)->forArt(29, $skill, [$skill]);

        $this->assertNotNull($display);
        $this->assertSame(100, $display['effective_power']);
    }

    public function test_job29_role_effect_execution_reapplies_the_rank5_v6_power(): void
    {
        $source = $this->masterArt(29);
        [$actor, $state] = $this->battle(29, [$source], 'holy_guard', 4);
        $execution = app(JobArtV2CrownBalanceCatalog::class)->applyToExecution($source);

        $this->beginAction($actor, $state);
        app(JobArtV2RoleEffectService::class)->applyForExecution(
            $actor,
            $state->enemy,
            $state,
            $source,
            $execution,
        );

        $this->assertSame(100, (int) $execution->power);
        $this->assertSame(1.0, (float) $execution->power_multiplier);
        $this->assertSame('MAGICAL_DAMAGE', (string) $execution->effect_template);
        $this->assertSame('magical', (string) $execution->damage_type);
        $this->assertSame(100, $state->jobArtV2RoleAction()['execution_power']);

        app(JobArtV2RoleEffectService::class)->completeJobArtCast(
            $actor,
            $state->enemy,
            $state,
            $source,
            HitResult::HIT,
        );
        $this->assertSame(20, $actor->damageReductionRate);

        $this->beginAction($actor, $state);
        $this->assertSame(0, $actor->damageReductionRate);
    }

    public function test_jobs15_29_and36_use_the_approved_guard_rates_only_while_rank5_v6_is_enabled(): void
    {
        $balances = app(JobArtV2CrownBalanceCatalog::class);
        $roles = app(JobArtV2RoleEffectService::class);

        $job15 = $balances->applyToExecution($this->masterArt(15));
        $this->assertSame(20, (int) $job15->damage_reduction_percent);

        foreach ([29, 36] as $jobId) {
            $skill = $this->masterArt($jobId);
            [$actor, $state] = $this->battle($jobId, [$skill], 'holy_guard', 4);
            $this->beginAction($actor, $state);
            $roles->completeJobArtCast($actor, $state->enemy, $state, $skill, HitResult::HIT);
            $this->assertSame(20, $actor->damageReductionRate, "job {$jobId}");
        }

        config(['battle.job_art_v2.rank5_v6' => false]);

        $legacyJob15 = $balances->applyToExecution($this->masterArt(15));
        $legacyJob29 = $balances->applyToExecution($this->masterArt(29));
        $this->assertSame(16, (int) $legacyJob15->damage_reduction_percent);
        $this->assertSame(18, (int) $legacyJob29->damage_reduction_percent);

        $job36 = $this->masterArt(36);
        [$actor, $state] = $this->battle(36, [$job36], 'holy_guard', 4);
        $this->beginAction($actor, $state);
        $roles->completeJobArtCast($actor, $state->enemy, $state, $job36, HitResult::HIT);
        $this->assertSame(0, $actor->damageReductionRate);
    }

    public function test_job15_guard_duration_is_catalog_driven_without_a_phantom_skill_attribute(): void
    {
        $balances = app(JobArtV2CrownBalanceCatalog::class);
        $execution = $balances->applyToExecution($this->masterArt(15));

        $this->assertTrue($balances->guardUntilNextOwnAction($execution));
        $this->assertArrayNotHasKey('job_art_v2_guard_until_next_own_action', $execution->getAttributes());
        $this->assertArrayNotHasKey('job_art_v2_guard_until_next_own_action', $execution->toArray());

        config(['battle.job_art_v2.rank5_v6' => false]);
        $legacy = $balances->applyToExecution($this->masterArt(15));
        $this->assertFalse($balances->guardUntilNextOwnAction($legacy));
    }

    public function test_reactive_rank_five_still_requires_four_lineage_resource_points(): void
    {
        $skill = $this->masterArt(85);
        [$actor, $state] = $this->battle(85, [$skill], 'star_mark', 3);
        app(JobArtV2FieldService::class)->deployPrimary($actor, $state, 'star_light', 8501, 1, 3);
        $service = $this->selection([1]);

        $blocked = $service->selectForTurn($actor, $state);
        $this->assertNull($blocked->candidateSkillId);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $blocked->blockedReasons[(int) $skill->id]);

        $actor->setResource('star_mark', 4);
        $ready = $service->selectForTurn($actor, $state);
        $this->assertSame((int) $skill->id, $ready->candidateSkillId);
        $this->assertTrue($ready->activated);
    }

    public function test_failed_rank_nine_activation_does_not_clear_the_rank_five_cycle(): void
    {
        $rankFive = $this->art(105, 1, 5, '受け返し');
        $rankNine = $this->art(109, 1, 9, '剣神一閃');
        [$actor, $state] = $this->battle(1, [$rankNine], 'sword_momentum', 12);
        $cycle = $actor->jobArtV2Rank5CycleState();
        $cycle->markUsed('sword_momentum', (int) $rankFive->id);

        $failed = $this->selection([100])->selectForTurn($actor, $state);

        $this->assertSame((int) $rankNine->id, $failed->candidateSkillId);
        $this->assertFalse($failed->activated);
        $this->assertTrue($cycle->hasUsed('sword_momentum', (int) $rankFive->id));
    }

    public function test_job66_cleanse_success_adds_exactly_one_holy_guard_point(): void
    {
        $skill = $this->masterArt(66);
        [$actor, $state] = $this->battle(66, [$skill], 'holy_guard', 4);
        $actor->conditions['poison'] = ['turns' => 3];
        $defense = app(JobArtV2DefenseService::class);

        $this->beginAction($actor, $state);
        $defense->applyJobArtCast($actor, $state, $skill);
        $defense->applyJobArtCast($actor, $state, $skill);

        $this->assertSame(5, $actor->getResource('holy_guard'));
        $this->assertArrayNotHasKey('poison', $actor->conditions);
    }

    public function test_job67_adds_exactly_two_catalyst_points_after_two_actions_without_resource_gain(): void
    {
        $producer = $this->art(6701, 67, 1, '金冠錬符');
        $rankFive = $this->masterArt(67);
        [$actor, $state] = $this->battle(67, [$producer, $rankFive], 'catalyst', 4);
        $target = $state->enemy;
        $target->currentJobId = 1;
        $targetArt = $this->art(101, 1, 1, '見切りの呼吸');
        $target->jobArts = [$targetArt];
        $target->jobArtOrigins[(int) $targetArt->id] = 'current';
        $target->configureResource('sword_momentum', 12);
        $progression = app(JobArtV2ProgressionService::class);

        $this->beginAction($actor, $state);
        $progression->completeJobArtCast($actor, $target, $state, $producer, HitResult::HIT);
        $this->assertNotSame([], $target->jobArtV2ProgressionState()->resourceSuppressions);

        $this->beginAction($actor, $state);
        $progression->completeJobArtCast($actor, $target, $state, $rankFive, HitResult::HIT);

        for ($action = 0; $action < 2; $action++) {
            $sourceActionId = app(JobArtV2ResourceService::class)->beginAction($target, $state);
            $this->assertNotNull($sourceActionId);
            $progression->beginAction($target, $state, $sourceActionId);
            $progression->finishAction($target, $state);
        }

        $this->assertSame(6, $actor->getResource('catalyst'));
    }

    public function test_transmute_cards_keep_no_sp_recovery_and_use_the_required_attack_routes(): void
    {
        $balances = app(JobArtV2CrownBalanceCatalog::class);
        $expected = [
            8 => ['幸運の一手', 100, 'PHYSICAL_DAMAGE_GOLD_REWARD', 1, 'physical'],
            20 => ['掘り出し物', 100, 'PHYSICAL_DAMAGE_REWARD', 1, 'physical'],
            31 => ['ゴールドラッシュ', 104, null, 4, 'physical'],
            57 => ['黄金転化', 194, null, null, 'magical'],
            77 => ['時詠み渡り', 231, null, null, 'magical'],
            91 => ['虚空導光', 288, null, null, 'magical'],
        ];

        foreach ($expected as $jobId => [$name, $power, $template, $hits, $damageType]) {
            $skill = $this->art(($jobId * 100) + 5, $jobId, 5, $name);
            $skill->mp_recover_percent = 99;
            $execution = $balances->applyToExecution($skill);
            $this->assertSame($power, (int) $execution->power, "job {$jobId}");
            $this->assertSame(0, (int) $execution->mp_recover_percent, "job {$jobId}");
            $this->assertSame($damageType, (string) $execution->damage_type, "job {$jobId}");
            if ($template !== null) {
                $this->assertSame($template, (string) $execution->effect_template, "job {$jobId}");
            }
            if ($hits !== null) {
                $this->assertSame($hits, (int) $execution->hit_count, "job {$jobId}");
            }
        }
    }

    public function test_job77_extends_only_the_shortest_positive_timed_effect(): void
    {
        [$actor, $state] = $this->battle(77, [], 'catalyst', 4);
        $skill = $this->art(7705, 77, 5, '時詠み渡り');
        $actor->replaceJobArtV2TimedEffect($this->timed('long', 4, 1));
        $actor->replaceJobArtV2TimedEffect($this->timed('short-later', 2, 3));
        $actor->replaceJobArtV2TimedEffect($this->timed('short-first', 2, 2));

        $this->beginAction($actor, $state);
        app(JobArtV2ProgressionService::class)->completeJobArtCast($actor, $state->enemy, $state, $skill, HitResult::HIT);

        $effects = [];
        foreach ($actor->jobArtV2TimedEffects() as $effect) {
            $effects[$effect->key] = $effect->remainingRounds;
        }
        $this->assertSame(4, $effects['short-first']);
        $this->assertSame(2, $effects['short-later']);
        $this->assertSame(4, $effects['long']);
        $this->assertCount(1, array_filter($state->logs, static fn (string $log): bool => str_contains($log, '2ラウンド延長')));
    }

    public function test_job91_applies_one_gold_corrosion_charge_without_duplicate_state(): void
    {
        [$actor, $state] = $this->battle(91, [], 'catalyst', 4);
        $skill = $this->art(9105, 91, 5, '虚空導光');
        $targetArt = $this->art(101, 1, 1, '見切りの呼吸');
        $state->enemy->currentJobId = 1;
        $state->enemy->jobArts = [$targetArt];
        $state->enemy->jobArtOrigins[101] = 'current';

        $this->beginAction($actor, $state);
        app(JobArtV2ProgressionService::class)->completeJobArtCast($actor, $state->enemy, $state, $skill, HitResult::HIT);

        $suppressions = $state->enemy->jobArtV2ProgressionState()->resourceSuppressions;
        $this->assertCount(1, $suppressions);
        $this->assertSame(1, array_values($suppressions)[0]['remaining_gains']);
    }

    public function test_focus_card_metadata_matches_redefined_effects(): void
    {
        $prototype = app(JobArtV2PrototypeCatalog::class);
        $balances = app(JobArtV2CrownBalanceCatalog::class);

        $this->assertSame(0.20, $prototype->artResourceMetadataForJobRank(11, 5)['parry_rate']);
        $this->assertSame(0.20, $prototype->artResourceMetadataForJobRank(11, 5)['guard_after_parry_rate']);
        $this->assertSame(0.25, $prototype->artResourceMetadataForJobRank(44, 5)['guard_rate']);
        $this->assertTrue($prototype->artResourceMetadataForJobRank(44, 5)['cleanse_on_guard_mitigation']);
        $this->assertSame(3, $prototype->artResourceMetadataForJobRank(46, 5)['field_extend_rounds']);
        $this->assertSame(1.20, $prototype->artResourceMetadataForJobRank(50, 5)['counter_damage_multiplier_after_parry']);
        $this->assertSame('redeploy_last_overwritten', $prototype->artResourceMetadataForJobRank(84, 5)['field_operation']);

        $debuff = $balances->applyToExecution($this->art(3605, 36, 5, '神罰の槌'));
        $this->assertSame(15, (int) $debuff->enemy_mag_down_percent);
    }

    public function test_job56_does_not_retain_the_legacy_stat_buff_in_v6(): void
    {
        $skill = $this->art(5605, 56, 5, '聖域結界');
        [$actor, $state] = $this->battle(56, [$skill], 'holy_guard', 4);

        $this->beginAction($actor, $state);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast(
            $actor,
            $state->enemy,
            $state,
            $skill,
            HitResult::HIT,
        );

        $this->assertSame([], $actor->jobArtV2TimedEffects());
        $this->assertSame([], app(JobArtV2CrownBalanceCatalog::class)->selfBuffModifiers($skill, $actor));
    }

    public function test_job44_guard_reduces_once_cleanses_after_real_mitigation_and_expires_at_next_action(): void
    {
        [$actor, $state] = $this->battle(44, [], 'holy_guard', 4);
        $skill = $this->art(4405, 44, 5, '聖盾裁き');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[4405] = 'current';
        $actor->conditions['poison'] = ['turns' => 3];
        $defense = app(JobArtV2DefenseService::class);

        $this->beginAction($actor, $state);
        $defense->applyJobArtCast($actor, $state, $skill);
        $this->assertSame(0.25, $actor->jobArtV2GuardState()?->rate);
        $this->assertTrue($actor->jobArtV2GuardState()?->expiresAtNextOwnAction ?? false);

        app(JobArtV2ResourceService::class)->beginAction($state->enemy, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $state->enemy,
            $actor,
            HitResult::HIT,
            'magical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(75, $defense->resolveDamage($state, $resolution, 100));
        $this->assertArrayNotHasKey('poison', $actor->conditions);
        $this->assertNull($actor->jobArtV2GuardState());

        $this->beginAction($actor, $state);
        $defense->applyJobArtCast($actor, $state, $skill);
        $this->assertNotNull($actor->jobArtV2GuardState());
        $this->beginAction($actor, $state);
        $this->assertNull($actor->jobArtV2GuardState());
    }

    public function test_job50_parry_arms_one_counter_lineage_multiplier(): void
    {
        [$actor, $state] = $this->battle(50, [], 'sword_momentum', 4);
        $skill = $this->art(5005, 50, 5, '聖剣烈破');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[5005] = 'current';
        $random = new class extends JobArtV2ParryRandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        };
        $defense = new JobArtV2DefenseService(
            app(JobArtV2FeatureGate::class),
            app(JobArtV2PrototypeCatalog::class),
            app(JobArtV2ResourceService::class),
            $random,
        );

        $this->beginAction($actor, $state);
        $defense->applyJobArtCast($actor, $state, $skill);
        app(JobArtV2ResourceService::class)->beginAction($state->enemy, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $state->enemy,
            $actor,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(1.20, $actor->jobArtV2ProgressionState()->rank5V6CounterDamageMultiplier);

        $counter = $this->art(105, 1, 5, '受け返し');
        $this->beginAction($actor, $state);
        $progression = app(JobArtV2ProgressionService::class);
        $progression->beginJobArtCast($actor, $state, $counter);
        $this->assertSame(120, $progression->modifyJobArtDamage($actor, $state, $counter, 100));
        $this->assertSame(1.0, $actor->jobArtV2ProgressionState()->rank5V6CounterDamageMultiplier);
    }

    public function test_job11_parry_arms_a_20_percent_guard_until_the_next_own_action(): void
    {
        [$actor, $state] = $this->battle(11, [], 'sword_momentum', 4);
        $skill = $this->art(1105, 11, 5, '居合斬り');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[1105] = 'current';
        $random = new class extends JobArtV2ParryRandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        };
        $defense = new JobArtV2DefenseService(
            app(JobArtV2FeatureGate::class),
            app(JobArtV2PrototypeCatalog::class),
            app(JobArtV2ResourceService::class),
            $random,
        );

        $this->beginAction($actor, $state);
        $defense->applyJobArtCast($actor, $state, $skill);
        app(JobArtV2ResourceService::class)->beginAction($state->enemy, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $state->enemy,
            $actor,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(0.20, $actor->jobArtV2GuardState()?->rate);

        $this->beginAction($actor, $state);
        $this->assertNull($actor->jobArtV2GuardState());
    }

    public function test_job12_prioritizes_a_different_stage_and_adds_20_activation_points(): void
    {
        [$actor, $state] = $this->battle(12, [], 'command_points', 4);
        $skill = $this->art(1205, 12, 5, '勝利の采配');
        $this->beginAction($actor, $state);
        $progression = app(JobArtV2ProgressionService::class);
        $progression->completeJobArtCast($actor, $state->enemy, $state, $skill, null);

        $sameStage = $this->art(8705, 87, 5, '時環支配');
        $differentStage = $this->art(8701, 87, 1, '時読み');
        $ordered = $progression->orderCandidates($actor, [$sameStage, $differentStage]);
        $this->assertSame(8701, (int) $ordered[0]->id);
        $this->assertSame(70.0, $progression->activationRate($actor, $differentStage, 50.0));
    }

    public function test_job46_extends_the_current_field_by_three_and_arms_immutable_rhythm(): void
    {
        [$actor, $state] = $this->battle(46, [], 'star_mark', 4);
        $skill = $this->art(4605, 46, 5, '祝福の大旋律');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[4605] = 'current';
        $field = app(JobArtV2FieldService::class);
        $field->deployPrimary($actor, $state, 'melody', 4601, 1, 3);

        $this->beginAction($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $skill);
        app(JobArtV2ProgressionService::class)->completeJobArtCast($actor, $state->enemy, $state, $skill, HitResult::HIT);

        $this->assertSame(6, $state->primaryField()?->remainingRounds);
        $this->assertSame(1, $actor->jobArtV2ProgressionState()->immutableRhythmCharges);
    }

    public function test_job84_redeploys_the_last_overwritten_own_field_for_five_rounds(): void
    {
        [$actor, $state] = $this->battle(84, [], 'star_mark', 4);
        $skill = $this->art(8405, 84, 5, '星海羅針');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[8405] = 'current';
        $field = app(JobArtV2FieldService::class);
        $field->deployPrimary($actor, $state, 'star_light', 8401, 1, 3);
        $field->deployPrimary($state->enemy, $state, 'melody', 4601, 2, 3);
        $this->assertSame('star_light', $state->lastOverwrittenFieldFor($actor)?->key);

        $this->beginAction($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $skill);

        $this->assertSame('star_light', $state->primaryField()?->key);
        $this->assertSame(5, $state->primaryField()?->remainingRounds);
    }

    public function test_job84_keeps_magical_damage_reward_classification_and_applies_both_effects(): void
    {
        $skill = $this->masterArt(84);
        $skill->setAttribute('sure_hit', true);
        [$actor, $state] = $this->battle(84, [$skill], 'star_mark', 4);
        $field = app(JobArtV2FieldService::class);
        $field->deployPrimary($actor, $state, 'star_light', 8401, 1, 3);
        $field->deployPrimary($state->enemy, $state, 'melody', 4601, 2, 3);
        $beforeHp = $state->enemy->hp;

        $this->assertSame('MAGICAL_DAMAGE_REWARD', (string) $skill->effect_template);
        $this->assertSame('reward', (string) $skill->art_category);
        $this->assertSame('REWARD', (string) $skill->limit_group);
        $this->assertSame('mixed', (string) $skill->reward_scope);
        $this->assertSame(2, (int) $skill->gold_bonus_percent);
        $this->assertSame(2, (int) $skill->drop_bonus_percent);

        $this->beginAction($actor, $state);
        (new \ReflectionMethod(BattleService::class, 'executeJobArtAction'))->invoke(
            app(BattleService::class),
            $actor,
            $state->enemy,
            $state,
            $skill,
        );

        $this->assertLessThan($beforeHp, $state->enemy->hp);
        $this->assertSame('star_light', $state->primaryField()?->key);
        $this->assertSame(5, $state->primaryField()?->remainingRounds);
        $this->assertSame(2, $state->goldBonusPercent);
        $this->assertSame(2, $state->dropBonusPercent);
    }

    public function test_job84_has_a_field_only_no_op_without_history_and_respects_field_lock(): void
    {
        [$actor, $state] = $this->battle(84, [], 'star_mark', 4);
        $skill = $this->art(8405, 84, 5, '星海羅針');
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[8405] = 'current';
        $field = app(JobArtV2FieldService::class);

        $this->beginAction($actor, $state);
        $noHistory = $field->applyJobArtCast($actor, $state, $skill);
        $this->assertFalse($noHistory->applied);
        $this->assertSame('no_overwritten_field', $noHistory->blockedReason);

        $field->deployPrimary($actor, $state, 'star_light', 8401, 2, 3);
        $field->deployPrimary($state->enemy, $state, 'melody', 4601, 3, 3);
        $field->lockPrimary($state->enemy, $state, 8505, 4);
        $this->beginAction($actor, $state);
        $blocked = $field->applyJobArtCast($actor, $state, $skill);
        $this->assertTrue($blocked->applied);
        $this->assertSame(JobArtV2FieldService::BLOCKED_BY_FIELD_LOCK, $blocked->blockedReason);
        $this->assertSame('melody', $state->primaryField()?->key);
    }

    public function test_job14_low_hp_and_job87_next_art_bonuses_use_battle_memory(): void
    {
        [$actor, $state] = $this->battle(14, [], 'eclipse', 4);
        $actor->hp = 50;
        $reckless = $this->art(1405, 14, 5, '暴走撃');
        $this->beginAction($actor, $state);
        $progression = app(JobArtV2ProgressionService::class);
        $progression->beginJobArtCast($actor, $state, $reckless);
        $this->assertSame(125, $progression->modifyJobArtDamage($actor, $state, $reckless, 100));

        [$timeActor, $timeState] = $this->battle(87, [], 'command_points', 4);
        $timeArt = $this->art(8705, 87, 5, '時環支配');
        $this->beginAction($timeActor, $timeState);
        $progression->completeJobArtCast($timeActor, $timeState->enemy, $timeState, $timeArt, HitResult::HIT);
        $next = $this->art(8701, 87, 1, '時読み');
        $this->assertSame(75.0, $progression->activationRate($timeActor, $next, 50.0));
        $progression->finishActivationAttempt($timeActor, $next, true);
        $this->beginAction($timeActor, $timeState);
        $progression->beginJobArtCast($timeActor, $timeState, $next);
        $this->assertSame(110, $progression->modifyJobArtDamage($timeActor, $timeState, $next, 100));
    }

    private function beginAction(BattleActor $actor, BattleState $state): int
    {
        $sourceActionId = app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        $this->assertNotNull($sourceActionId);
        app(JobArtV2RoleEffectService::class)->beginAction($actor, $state, $sourceActionId);

        return $sourceActionId;
    }

    /** @param list<Skill> $arts @return array{BattleActor, BattleState} */
    private function battle(int $currentJobId, array $arts, string $resourceKey, int $points): array
    {
        $actor = $this->actor($currentJobId);
        $actor->jobArts = $arts;
        foreach ($arts as $art) {
            $actor->jobArtOrigins[(int) $art->id] = (int) $art->job_id === $currentJobId ? 'current' : 'inherited';
        }
        $actor->configureResource($resourceKey, 12);
        $actor->setResource($resourceKey, $points);
        $enemy = new BattleActor('enemy', false, ['hp' => 1000, 'max_hp' => 1000, 'mp' => 100, 'max_mp' => 100]);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function actor(int $currentJobId): BattleActor
    {
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 1000,
            'max_mp' => 1000,
        ]);
        $actor->currentJobId = $currentJobId;
        $actor->jobArtActivationPolicy = 'aggressive';

        return $actor;
    }

    private function selection(array $rolls): JobArtV2SelectionService
    {
        $random = new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };

        return new JobArtV2SelectionService(
            $random,
            new JobArtV2FinisherConditionProvider(),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
        );
    }

    private function art(
        int $id,
        int $jobId,
        int $rank,
        string $name,
        string $effectTemplate = 'PHYSICAL_DAMAGE',
        string $damageType = 'physical',
    ): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'job_id' => $jobId,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'effect_template' => $effectTemplate,
            'damage_type' => $damageType,
            'power' => 145,
            'power_multiplier' => 1.45,
            'hit_count' => 1,
            'mp_recover_percent' => 0,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function masterArt(int $jobId): Skill
    {
        $rows = json_decode(
            (string) file_get_contents(database_path('data/job_arts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $row = collect($rows)->first(
            static fn (array $candidate): bool => (int) $candidate['job_id'] === $jobId
                && (int) $candidate['learn_rank'] === 5,
        );
        $this->assertIsArray($row);

        $power = (int) ($row['power_hint'] ?? 0);
        $template = (string) ($row['effect_template'] ?? 'PHYSICAL_DAMAGE');
        $skill = new Skill(array_merge($row, [
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => (int) ($row['hit_count'] ?? \App\Support\JobArtEffectCatalog::hitCount($template)),
            'damage_type' => (string) ($row['damage_type'] ?? \App\Support\JobArtEffectCatalog::damageType($template)),
        ]));
        $skill->setAttribute('id', ($jobId * 100) + 5);

        return $skill;
    }

    private function timed(string $key, int $remaining, int $appliedRound): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: ['str' => 0.10],
            appliedRound: $appliedRound,
            remainingRounds: $remaining,
            sourceActionId: $appliedRound,
            sourceSkillId: $appliedRound,
            removable: true,
            strength: 10,
        );
    }
}
