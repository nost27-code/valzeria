<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\ChampBattleService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PreparedEffectState;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2TimedEffectState;
use App\Services\PvPBattleService;
use App\Services\TowerBattleService;
use ReflectionMethod;
use Tests\TestCase;

class JobArtV2RoleDiversityWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.normalized_sp' => false,
            'battle.job_art_v2.c_design_prototype' => false,
            'battle.job_art_v2.ultimate_counterplay' => false,
        ]);
    }

    public function test_all_six_routes_wire_the_shared_role_lifecycle_once_per_action_branch(): void
    {
        $pveAction = $this->methodSource(BattleService::class, 'executeAction');
        $pveArt = $this->methodSource(BattleService::class, 'executeJobArtAction');
        $pveRoundEnd = $this->methodSource(BattleService::class, 'endJobArtV2Round');

        $this->assertSame(1, substr_count($pveAction, 'jobArtV2RoleEffectService->beginAction('));
        $this->assertSame(1, substr_count($pveArt, 'jobArtV2RoleEffectService->applyForExecution('));
        $this->assertSame(1, substr_count($pveArt, 'jobArtV2RoleEffectService->beginJobArtCast('));
        // Field-only, MISS/EVADE, and resolved branches are mutually exclusive
        // and every branch returns or reaches exactly one completion call.
        $this->assertSame(3, substr_count($pveArt, 'jobArtV2RoleEffectService->completeJobArtCast('));
        $this->assertSame(1, substr_count($pveRoundEnd, 'jobArtV2RoleEffectService->endRound('));

        foreach ([
            [PvPBattleService::class, 'executeAction'],
            [ArenaNpcBattleService::class, 'executeAction'],
        ] as [$service, $method]) {
            $source = $this->methodSource($service, $method);
            foreach ([
                'jobArtBattleSupport->beginAction(',
                'jobArtBattleSupport->consumeAndMarkUse(',
                'jobArtBattleSupport->skillForExecution(',
                'jobArtBattleSupport->completeJobArtCast(',
                'jobArtBattleSupport->finishAction(',
            ] as $hook) {
                $this->assertSame(1, substr_count($source, $hook), "{$service}::{$method} {$hook}");
            }
        }
        $champAction = $this->methodSource(ChampBattleService::class, 'champAction');
        foreach ([
            'jobArtBattleSupport->beginAction(',
            'jobArtBattleSupport->consumeAndMarkUse(',
            'jobArtBattleSupport->skillForExecution(',
            'jobArtBattleSupport->completeJobArtCast(',
        ] as $hook) {
            $this->assertSame(1, substr_count($champAction, $hook), "ChampBattleService::champAction {$hook}");
        }
        $this->assertSame(0, substr_count($champAction, 'jobArtBattleSupport->finishAction('));
        $this->assertSame(
            1,
            substr_count($this->methodSource(ChampBattleService::class, 'runBattle'), 'jobArtBattleSupport->finishAction('),
            'ChampBattleService::runBattle finishAction',
        );
        $supportDelegations = [
            'beginAction' => 'jobArtV2RoleEffectService->beginAction(',
            'consumeAndMarkUse' => 'jobArtV2RoleEffectService->beginJobArtCast(',
            'skillForExecution' => 'jobArtV2RoleEffectService->applyForExecution(',
            'completeJobArtCast' => 'jobArtV2RoleEffectService->completeJobArtCast(',
            'recordNormalAttackResolution' => 'jobArtV2RoleEffectService->markNonJobArtAction(',
            'markSkillAction' => 'jobArtV2RoleEffectService->markNonJobArtAction(',
            'endRound' => 'jobArtV2RoleEffectService->endRound(',
            'modifyJobArtDamage' => 'jobArtV2RoleEffectService->modifyJobArtDamage(',
            'damageStatOverrides' => 'jobArtV2RoleEffectService->damageStatOverrides(',
            'criticalBonusPoints' => 'jobArtV2RoleEffectService->criticalBonusPoints(',
            'applyTimedStructuredDebuffs' => 'jobArtV2RoleEffectService->applyTimedStructuredDebuffs(',
        ];
        foreach ($supportDelegations as $method => $hook) {
            $this->assertSame(
                1,
                substr_count($this->methodSource(JobArtBattleSupportService::class, $method), $hook),
                "JobArtBattleSupportService::{$method}",
            );
        }

        $this->assertSame(
            BattleService::class,
            (new ReflectionMethod(TowerBattleService::class, 'executeAction'))->getDeclaringClass()->getName(),
        );
        $this->assertStringContainsString("$".'battleContext = $'."enemy->is_boss ? 'boss' : 'pve'", file_get_contents(app_path('Services/BattleService.php')));
        $this->assertStringContainsString("new BattleState($".'player, $'."enemy, 'pve')", file_get_contents(app_path('Services/TowerBattleService.php')));

        foreach ([PvPBattleService::class, ChampBattleService::class, ArenaNpcBattleService::class] as $service) {
            $this->assertStringContainsString(
                'jobArtBattleSupport->endRound(',
                file_get_contents((new ReflectionMethod($service, '__construct'))->getFileName()),
                $service,
            );
            $this->assertStringContainsString(
                'jobArtBattleSupport->applySharedSelfBuff(',
                $this->methodSource($service, 'applyJobArtTemplateEffects'),
                $service,
            );
        }
        foreach (['executePhysicalAttack', 'executeMagicalAttack'] as $method) {
            $this->assertStringContainsString(
                'jobArtV2RoleEffectService->damageStatOverrides(',
                $this->methodSource(BattleService::class, $method),
                "BattleService::{$method}",
            );
        }
        foreach ([
            [PvPBattleService::class, 'executeSkillAction'],
            [ChampBattleService::class, 'skillAttack'],
            [ArenaNpcBattleService::class, 'executeSkillAction'],
        ] as [$service, $method]) {
            $this->assertStringContainsString(
                'jobArtBattleSupport->damageStatOverrides(',
                $this->methodSource($service, $method),
                "{$service}::{$method}",
            );
        }
        $this->assertStringContainsString(
            'jobArtV2RoleEffectService->applySharedSelfBuff(',
            $this->methodSource(BattleService::class, 'applySelfBuff'),
        );
        $this->assertStringContainsString(
            'jobArtV2RoleEffectService->applyTimedStructuredDebuffs(',
            $this->methodSource(BattleService::class, 'applyStructuredDebuffs'),
        );
        foreach ([PvPBattleService::class, ChampBattleService::class, ArenaNpcBattleService::class] as $service) {
            $this->assertStringContainsString(
                'jobArtBattleSupport->applyTimedStructuredDebuffs(',
                $this->methodSource($service, 'applyStructuredDebuffs'),
                $service,
            );
        }

        $this->assertStringNotContainsString(
            'isRankBattleCritical(',
            $this->methodSource(PvPBattleService::class, 'executeSkillAction'),
        );
        $this->assertStringNotContainsString(
            'isDuelCritical(',
            $this->methodSource(ChampBattleService::class, 'skillAttack'),
        );
        $this->assertStringNotContainsString(
            'isRankBattleCritical(',
            $this->methodSource(ArenaNpcBattleService::class, 'executeSkillAction'),
        );
    }

    public function test_completion_claim_makes_shared_support_idempotent_for_the_same_source_action(): void
    {
        $support = app(JobArtBattleSupportService::class);
        $actor = $this->actor('eclipse', 61, 1_000);
        $target = $this->actor('target', 60, 1_000);
        $state = new BattleState($actor, $target, 'pvp');
        $skill = $this->art(14, 1, '血潮の咆哮', 'SELF_BUFF', 1_401);
        $skill->self_damage_percent = 30;
        $skill->self_buff_percent = 30;
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = 1.0;

        $this->assertNotNull($support->beginAction($actor, $state));
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $skill));
        $execution = $support->skillForExecution($actor, $skill, $state, $target);
        $this->assertSame(0, (int) $execution->self_damage_percent);
        $this->assertSame(0, (int) $execution->self_buff_percent);

        $support->completeJobArtCast($actor, $state, $skill, HitResult::HIT, $target);
        $support->completeJobArtCast($actor, $state, $skill, HitResult::HIT, $target);

        $this->assertSame(970, $actor->hp);
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertSame(30, $actor->jobArtV2ProgressionState()->nightmareSelfDamage);
        $this->assertCount(1, $actor->jobArtV2TimedEffects());
    }

    public function test_competitive_routes_keep_reward_art_damage_but_clear_every_reward_bonus(): void
    {
        foreach (['pvp', 'champ', 'arena_npc'] as $battleType) {
            $support = app(JobArtBattleSupportService::class);
            $actor = $this->actor('transmuter', 67, 1_000);
            $target = $this->actor('target', 60, 1_000);
            $state = new BattleState($actor, $target, $battleType);
            $state->goldBonusPercent = 10;
            $state->dropBonusPercent = 8;
            $state->rareBonusPercent = 4;
            $state->materialBonusPercent = 6;
            $skill = $this->art(49, 5, '大錬成爆装', 'MAGICAL_DAMAGE_REWARD', 4_905, 255, 1);
            $skill->damage_type = 'magical';
            $skill->gold_bonus_percent = 7;
            $skill->drop_bonus_percent = 6;
            $skill->rare_bonus_percent = 3;
            $skill->material_bonus_percent = 2;
            $skill->reward_scope = 'normal_exploration_win_only';
            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
            $actor->jobArtRates[(int) $skill->id] = 1.0;

            $this->assertNotNull($support->beginAction($actor, $state));
            $execution = $support->skillForExecution($actor, $skill, $state, $target);

            $this->assertSame('MAGICAL_DAMAGE_REWARD', (string) $execution->effect_template, $battleType);
            $this->assertSame('magical', (string) $execution->damage_type, $battleType);
            $this->assertGreaterThan(0, (int) $execution->power, $battleType);
            $this->assertSame(1, (int) $execution->hit_count, $battleType);
            $this->assertSame('none', (string) $execution->reward_scope, $battleType);
            $this->assertSame(0, (int) $execution->gold_bonus_percent, $battleType);
            $this->assertSame(0, (int) $execution->drop_bonus_percent, $battleType);
            $this->assertSame(0, (int) $execution->rare_bonus_percent, $battleType);
            $this->assertSame(0, (int) $execution->material_bonus_percent, $battleType);
            $this->assertSame(0, $state->goldBonusPercent, $battleType);
            $this->assertSame(0, $state->dropBonusPercent, $battleType);
            $this->assertSame(0, $state->rareBonusPercent, $battleType);
            $this->assertSame(0, $state->materialBonusPercent, $battleType);

            $this->assertSame(7, (int) $skill->gold_bonus_percent, 'source skill must not be mutated');
            $this->assertSame(6, (int) $skill->drop_bonus_percent, 'source skill must not be mutated');
        }
    }

    public function test_field_then_role_multiplier_then_damage_application_order_is_shared(): void
    {
        foreach (['executePhysicalAttack', 'executeMagicalAttack', 'executeHybridJobArtAttack'] as $method) {
            $this->assertInSourceOrder($this->methodSource(BattleService::class, $method), [
                'jobArtV2FieldService->modifyDamage(',
                'jobArtV2RoleEffectService->modifyJobArtDamage(',
                'applyResolvedDamage(',
            ], "BattleService::{$method}");
        }
        foreach ([
            [PvPBattleService::class, 'executeSkillAction'],
            [ArenaNpcBattleService::class, 'executeSkillAction'],
        ] as [$service, $method]) {
            $this->assertInSourceOrder($this->methodSource($service, $method), [
                'jobArtBattleSupport->modifyFieldDamage(',
                'jobArtBattleSupport->modifyJobArtDamage(',
                'applyResolvedDamage(',
            ], "{$service}::{$method}");
        }
        $this->assertInSourceOrder($this->methodSource(ChampBattleService::class, 'skillAttack'), [
            'jobArtBattleSupport->modifyFieldDamage(',
            'jobArtBattleSupport->modifyJobArtDamage(',
        ], 'ChampBattleService::skillAttack');
        $this->assertInSourceOrder($this->methodSource(ChampBattleService::class, 'runBattle'), [
            '$action = $this->champAction(',
            '$damageResult = $this->applyResolvedDamage(',
            '$this->jobArtBattleSupport->finishAction(',
        ], 'ChampBattleService::runBattle');

        $support = app(JobArtBattleSupportService::class);
        $field = app(JobArtV2FieldService::class);
        $damageApplication = app(DamageApplicationService::class);
        $actor = $this->actor('counter', 60, 1_000);
        $target = $this->actor('target', 61, 1_000);
        $state = new BattleState($actor, $target, 'pve');
        $fieldActionId = $state->beginSourceAction();
        $field->deployPrimary($actor, $state, 'star_light', 9_000, $fieldActionId);
        $actor->configureResource('sword_momentum', 12);
        $actor->setResource('sword_momentum', 12);
        $actor->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            key: 'counter_focus',
            multiplier: 1.20,
            appliedRound: 0,
            remainingRounds: null,
            charges: 2,
            sourceActionId: $fieldActionId,
            sourceSkillId: 2_801,
            targetLineage: 'counter',
            targetRanks: [5, 9],
            strictNextAction: false,
            group: 'counter_focus',
            remainingActionOpportunities: 6,
        ));
        $skill = $this->art(11, 9, '配線試験魔法反撃', 'MAGICAL_DAMAGE', 1_109, 100, 1);
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = 1.0;

        $this->assertNotNull($support->beginAction($actor, $state));
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $skill));
        $execution = $support->skillForExecution($actor, $skill, $state, $target);
        $fieldDamage = $support->modifyFieldDamage($actor, $state, 100, DamageSourceType::JOB_ART);
        $roleDamage = $support->modifyJobArtDamage($actor, $state, $execution, $fieldDamage);
        $result = $damageApplication->apply(new DamageApplicationRequest(
            sourceActor: $actor,
            targetActor: $target,
            resolvedDamage: $roleDamage,
            sourceType: DamageSourceType::JOB_ART,
            sourceId: (int) $skill->id,
            battleType: 'pve',
            hitResult: HitResult::HIT,
            battleState: $state,
        ));

        $this->assertSame(110, $fieldDamage);
        $this->assertSame(132, $roleDamage);
        $this->assertSame(132, $result->requestedDamage);
        $this->assertSame(868, $target->hp);
    }

    public function test_legacy_flag_off_does_not_touch_role_state_or_rng(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.hit_resolution' => false,
            'battle.job_art_v2.damage_application' => false,
            'battle.job_art_v2.resources' => false,
            'battle.job_art_v2.fields' => false,
        ]);
        $support = app(JobArtBattleSupportService::class);
        $actor = $this->actor('legacy', 61, 1_000);
        $target = $this->actor('target', 60, 1_000);
        $state = new BattleState($actor, $target, 'pve');
        $state->addLog('before');
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'existing',
            statModifiers: ['str' => 0.10],
            appliedRound: 0,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 10,
        ));
        $actor->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            key: 'strict',
            multiplier: 1.15,
            appliedRound: 0,
            remainingRounds: 2,
            charges: 1,
            sourceActionId: 1,
            sourceSkillId: 1,
            targetLineage: 'counter',
            targetRanks: [5, 9],
            strictNextAction: true,
            group: 'strict',
        ));
        $skill = $this->art(14, 1, '血潮の咆哮', 'SELF_BUFF', 1_401);
        $skill->self_damage_percent = 30;

        mt_srand(72_319);
        $expectedNext = mt_rand();
        mt_srand(72_319);

        $this->assertNull($support->beginAction($actor, $state));
        $execution = $support->skillForExecution($actor, $skill, $state, $target);
        $support->completeJobArtCast($actor, $state, $skill, HitResult::HIT, $target);
        $support->markSkillAction($actor, $state, new Skill(['name' => 'legacy special', 'skill_type' => 'special']));
        $support->endRound($state);

        $this->assertSame(30, (int) $execution->self_damage_percent);
        $this->assertSame(1_000, $actor->hp);
        $this->assertSame(2, $actor->jobArtV2TimedEffect('existing')?->remainingRounds);
        $this->assertSame(1, $actor->jobArtV2PreparedEffect('strict')?->charges);
        $this->assertSame([], $state->jobArtV2RoleAction());
        $this->assertSame(['before'], $state->logs);
        $this->assertSame($expectedNext, mt_rand());
    }

    public function test_inherited_aim_accuracy_is_action_local_and_fail_closed(): void
    {
        $attacker = $this->actor('inheritor', 60);
        $defender = $this->actor('target', 61);
        $aim = $this->art(4, 5, '狙い撃ち', 'PHYSICAL_DAMAGE', 405);
        $attacker->jobArtOrigins[(int) $aim->id] = 'inherited';

        $atCap = $this->random([98]);
        $overCap = $this->random([99]);
        $this->assertSame(HitResult::HIT, $this->resolver($atCap)->resolveJobArt($attacker, $defender, $aim, 'pve'));
        $this->assertSame(HitResult::MISS, $this->resolver($overCap)->resolveJobArt($attacker, $defender, $aim, 'pve'));
        $this->assertSame(1, $atCap->calls);
        $this->assertSame(1, $overCap->calls);

        $defender->agi = 140;
        $fastTargetHit = $this->random([82]);
        $fastTargetMiss = $this->random([83]);
        $this->assertSame(HitResult::HIT, $this->resolver($fastTargetHit)->resolveJobArt($attacker, $defender, $aim, 'pve'));
        $this->assertSame(HitResult::MISS, $this->resolver($fastTargetMiss)->resolveJobArt($attacker, $defender, $aim, 'pve'));
        $defender->agi = 100;

        $critical = $this->art(18, 5, 'クリティカルショット', 'PHYSICAL_DAMAGE', 1_805);
        $attacker->jobArtOrigins[(int) $critical->id] = 'inherited';
        $criticalHit = $this->random([96]);
        $criticalMiss = $this->random([97]);
        $this->assertSame(HitResult::HIT, $this->resolver($criticalHit)->resolveJobArt($attacker, $defender, $critical, 'pve'));
        $this->assertSame(HitResult::MISS, $this->resolver($criticalMiss)->resolveJobArt($attacker, $defender, $critical, 'pve'));
        $this->assertSame(10.0, app(JobArtBattleSupportService::class)->criticalBonusPoints($attacker, $critical));

        foreach (['pvp', 'champ', 'arena_npc'] as $context) {
            $aimSureHit = $this->random([100]);
            $this->assertSame(HitResult::HIT, $this->resolver($aimSureHit)->resolveJobArt($attacker, $defender, $aim, $context));
            $this->assertSame(1, $aimSureHit->calls);

            $criticalHit = $this->random([96]);
            $criticalMiss = $this->random([97]);
            $this->assertSame(HitResult::HIT, $this->resolver($criticalHit)->resolveJobArt($attacker, $defender, $critical, $context));
            $this->assertSame(HitResult::MISS, $this->resolver($criticalMiss)->resolveJobArt($attacker, $defender, $critical, $context));
        }

        $explicitAccuracy = clone $aim;
        $explicitAccuracy->accuracy = 70;
        $explicitHit = $this->random([96]);
        $explicitMiss = $this->random([97]);
        $this->assertSame(HitResult::HIT, $this->resolver($explicitHit)->resolveJobArt($attacker, $defender, $explicitAccuracy, 'pvp'));
        $this->assertSame(HitResult::MISS, $this->resolver($explicitMiss)->resolveJobArt($attacker, $defender, $explicitAccuracy, 'pvp'));

        config(['battle.job_art_v2.resources' => false]);
        $disabled = $this->random([91]);
        $this->assertSame(HitResult::MISS, $this->resolver($disabled)->resolveJobArt($attacker, $defender, $aim, 'pve'));
        $this->assertSame(0.0, app(JobArtBattleSupportService::class)->criticalBonusPoints($attacker, $critical));

        config(['battle.job_art_v2.resources' => true]);
        $special = $this->art(4, 5, '狙い撃ち', 'PHYSICAL_DAMAGE', 4_405);
        $special->skill_type = 'special';
        $specialRandom = $this->random([1]);
        $this->assertNull($this->resolver($specialRandom)->resolveJobArt($attacker, $defender, $special, 'pve'));
        $this->assertSame(0, $specialRandom->calls);
    }

    public function test_dynamic_single_without_resources_keeps_legacy_support_eligibility(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.resources' => false,
            'battle.job_art_v2.fields' => false,
        ]);
        $actor = $this->actor('legacy support', 60, 1_000);
        $target = $this->actor('target', 61, 1_000);
        $state = new BattleState($actor, $target, 'pvp');
        $medicine = $this->art(47, 1, '聖薬散布', 'REWARD_MIXED', 4_701, 0, 0);
        $medicine->damage_type = 'support';
        $actor->jobArtOrigins[(int) $medicine->id] = 'inherited';

        $this->assertFalse(app(JobArtBattleSupportService::class)->usesRoleEffects($actor));
        $this->assertTrue(
            app(JobArtV2SelectionService::class)->isEligible($actor, $state, $medicine, 'legacy-support'),
        );
    }

    public function test_portable_damage_replacement_receives_formal_hit_resolution_from_execution_clone(): void
    {
        $support = app(JobArtBattleSupportService::class);
        $attacker = $this->actor('field inheritor', 60);
        $defender = $this->actor('target', 61);
        $state = new BattleState($attacker, $defender, 'pve');
        $source = $this->art(6, 1, '魔力の火種', 'SELF_BUFF', 601, 100, 1);
        $attacker->jobArtOrigins[(int) $source->id] = 'inherited';

        $this->assertNotNull($support->beginAction($attacker, $state));
        $execution = $support->skillForExecution($attacker, $source, $state, $defender);

        $this->assertNotSame($source, $execution);
        $this->assertSame('SELF_BUFF', (string) $source->effect_template);
        $this->assertSame('MAGICAL_DAMAGE', (string) $execution->effect_template);
        $this->assertSame('magical', (string) $execution->damage_type);

        $random = $this->random([100]);
        $this->assertSame(
            HitResult::MISS,
            $this->resolver($random)->resolveJobArt($attacker, $defender, $execution, 'pve', $state),
        );
        $this->assertSame(1, $random->calls);
    }

    public function test_pvp_champ_and_arena_resolve_execution_clone_but_keep_source_identity(): void
    {
        foreach ([
            [PvPBattleService::class, 'executeAction', '$state->battleType', '$state'],
            [ChampBattleService::class, 'champAction', "'champ'", '$jobArtState'],
            [ArenaNpcBattleService::class, 'executeAction', '$state->battleType', '$state'],
        ] as [$service, $method, $battleType, $state]) {
            $source = $this->methodSource($service, $method);
            $this->assertInSourceOrder($source, [
                '$executionSkill = $this->jobArtBattleSupport->skillForExecution(',
                '$hitResult = $this->jobArtBattleSupport->resolveHit(',
            ], "{$service}::{$method}");
            $this->assertStringContainsString(
                "resolveHit(\$attacker, \$defender, \$executionSkill, {$battleType}, {$state})",
                $source,
                "{$service}::{$method}",
            );
            $this->assertSame(3, substr_count($source, '$executionSkill'), "{$service}::{$method}");
            $this->assertStringContainsString(
                'consumeAndMarkUse(',
                $source,
                "{$service}::{$method}",
            );
            $this->assertStringContainsString(
                'completeJobArtCast($attacker, '.$state.', $jobArt, $hitResult, $defender)',
                $source,
                "{$service}::{$method}",
            );
            $this->assertStringContainsString(
                'activationLog($attacker, $defender, $jobArt)',
                $source,
                "{$service}::{$method}",
            );
            $this->assertStringContainsString(
                'resolutionFailureLog($jobArt, $hitResult)',
                $source,
                "{$service}::{$method}",
            );
        }
    }

    private function resolver(JobArtV2HitRandomSource $random): ActionResolver
    {
        return new ActionResolver(
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            new DamageCalculator(),
            $random,
            new JobArtV2ActiveEvasionProvider(),
            app(JobArtV2FieldService::class),
            new JobArtV2PrototypeCatalog(),
            new JobArtV2RoleEffectCatalog(),
        );
    }

    private function random(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };
    }

    private function actor(string $name, ?int $jobId, int $hp = 10_000): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $template,
        int $id,
        int $power = 225,
        int $hitCount = 1,
    ): Skill {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => $template === 'MAGICAL_DAMAGE' ? 'magical' : 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => $hitCount,
            'activation_rate' => 100,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    /** @param list<string> $needles */
    private function assertInSourceOrder(string $source, array $needles, string $message): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = strpos($source, $needle, $offset);
            $this->assertNotFalse($position, "{$message}: missing {$needle}");
            $offset = $position + strlen($needle);
        }
    }
}
