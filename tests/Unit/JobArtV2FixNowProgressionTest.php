<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\Battle\JobArtHitPower;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectService;
use Tests\TestCase;

final class JobArtV2FixNowProgressionTest extends TestCase
{
    private int $nextSkillId = 950_000;

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
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_white_silver_shield_is_guard_lineage_only_and_uses_inheritance_attenuation(): void
    {
        [$guard, $target, $state] = $this->battle(66);
        $shield = $this->art(79, 5, '白銀王盾', 'DAMAGE_BUFF', 285, attributes: [
            'self_buff_percent' => 20,
        ]);
        $this->inherit($guard, $shield, 0.75);

        $this->assertNull(app(JobArtV2ProgressionService::class)->eligibilityBlockReason($guard, $state, $shield));
        $execution = $this->cast($guard, $target, $state, $shield);

        $this->assertSame('PHYSICAL_DAMAGE', $execution->effect_template);
        $this->assertSame(0, (int) $execution->self_buff_percent);
        $effect = $guard->jobArtV2TimedEffect('silver_guard_bridge');
        $this->assertNotNull($effect);
        $this->assertSame(['def' => 0.11249999999999999, 'spr' => 0.11249999999999999], $effect->statModifiers);
        $this->assertSame(2, $effect->remainingRounds);

        [$cross, $crossTarget, $crossState] = $this->battle(62);
        $crossShield = clone $shield;
        $crossShield->setAttribute('id', ++$this->nextSkillId);
        $this->inherit($cross, $crossShield);
        $progression = app(JobArtV2ProgressionService::class);
        $this->assertSame(
            JobArtV2ProgressionService::BLOCKED_BY_DAMAGE_MITIGATION,
            $progression->eligibilityBlockReason($cross, $crossState, $crossShield),
        );

        $this->assertSame(80, $this->qualifyingMitigation($cross, $crossTarget, $crossState));
        $this->assertTrue($cross->jobArtV2ProgressionState()->silverShieldReady);
        $this->assertNull($progression->eligibilityBlockReason($cross, $crossState, $crossShield));

        $crossExecution = $this->cast($cross, $crossTarget, $crossState, $crossShield, HitResult::MISS);

        $this->assertSame('PHYSICAL_DAMAGE', $crossExecution->effect_template);
        $this->assertSame([285, 100], [(int) $crossExecution->power, (int) $crossExecution->activation_rate]);
        $this->assertSame(0, (int) $crossExecution->self_buff_percent);
        $this->assertNull($cross->jobArtV2TimedEffect('silver_guard_bridge'));
        $this->assertSame(0, $cross->getResource('holy_guard'));
        $this->assertFalse($cross->jobArtV2ProgressionState()->silverShieldReady);
    }

    public function test_cross_lineage_white_silver_shield_latch_expires_on_the_next_other_action(): void
    {
        [$actor, $target, $state] = $this->battle(62);
        $shield = $this->art(79, 5, '白銀王盾', 'DAMAGE_BUFF', 285);
        $this->inherit($actor, $shield);
        $progression = app(JobArtV2ProgressionService::class);

        $sourceActionId = $this->beginAction($target, $state);
        $plainHit = new DirectAttackResolution(
            sourceActionId: $sourceActionId,
            attacker: $target,
            target: $actor,
            hitResult: HitResult::HIT,
            damageCategory: 'physical',
            direct: true,
            actionType: BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(100, app(JobArtV2DefenseService::class)->resolveDamage($state, $plainHit, 100));
        $this->assertSame(
            JobArtV2ProgressionService::BLOCKED_BY_DAMAGE_MITIGATION,
            $progression->eligibilityBlockReason($actor, $state, $shield),
        );

        $this->qualifyingMitigation($actor, $target, $state);
        $this->assertTrue($actor->jobArtV2ProgressionState()->silverShieldReady);

        $this->beginAction($actor, $state);
        $progression->finishActivationAttempt($actor, $shield);
        $this->assertTrue(
            $actor->jobArtV2ProgressionState()->silverShieldReady,
            'An activation-roll failure must not consume the latch at selection time.',
        );
        $this->resources()->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $this->resources()->finishAction($actor, $state);
        $this->assertFalse($actor->jobArtV2ProgressionState()->silverShieldReady);
        $this->assertSame(
            JobArtV2ProgressionService::BLOCKED_BY_DAMAGE_MITIGATION,
            $progression->eligibilityBlockReason($actor, $state, $shield),
        );
    }

    public function test_cross_lineage_white_silver_shield_consumes_latch_for_every_hit_result(): void
    {
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(62);
            $shield = $this->art(79, 5, '白銀王盾', 'DAMAGE_BUFF', 285);
            $this->inherit($actor, $shield);
            $this->qualifyingMitigation($actor, $target, $state);

            $this->cast($actor, $target, $state, $shield, $hitResult);

            $this->assertFalse(
                $actor->jobArtV2ProgressionState()->silverShieldReady,
                $hitResult->value,
            );
        }
    }

    public function test_non_direct_damage_never_arms_cross_lineage_white_silver_shield(): void
    {
        foreach ([DamageSourceType::DOT, DamageSourceType::SELF_DAMAGE, DamageSourceType::REFLECT] as $sourceType) {
            [$actor, $target, $state] = $this->battle(62);
            $shield = $this->art(79, 5, '白銀王盾', 'DAMAGE_BUFF', 285);
            $this->inherit($actor, $shield);
            app(JobArtV2DefenseService::class)->applyGuard($actor, $state, 0.20);

            app(DamageApplicationService::class)->apply(new DamageApplicationRequest(
                sourceActor: $target,
                targetActor: $actor,
                resolvedDamage: 100,
                sourceType: $sourceType,
                sourceId: 'non-direct',
                battleType: $state->battleType,
                battleState: $state,
                directAttackResolution: null,
            ));

            $this->assertFalse($actor->jobArtV2ProgressionState()->silverShieldReady, $sourceType->value);
            $this->assertSame(
                JobArtV2ProgressionService::BLOCKED_BY_DAMAGE_MITIGATION,
                app(JobArtV2ProgressionService::class)->eligibilityBlockReason($actor, $state, $shield),
                $sourceType->value,
            );
        }
    }

    public function test_magic_aim_preparation_is_same_lineage_rng_free_and_consumed_only_by_execution(): void
    {
        [$actor, $target, $state] = $this->battle(65, targetOverrides: ['def' => 40, 'spr' => 800]);
        $actor->str = 700;
        $actor->mag = 50;
        $load = $this->art(22, 1, '魔矢装填', 'MAGICAL_DAMAGE', 100);
        $this->inherit($actor, $load);
        $this->cast($actor, $target, $state, $load);

        $prepared = $actor->jobArtV2PreparedEffect('magic_aim_prep');
        $this->assertNotNull($prepared);
        $this->assertSame([1, 3], [$prepared->charges, $prepared->remainingActionOpportunities]);

        $rankFive = $this->art(65, 5, '鋼冠機砲', 'MAGICAL_DAMAGE', 285);
        $this->current($actor, $rankFive);
        $this->beginAction($actor, $state);
        $execution = clone $rankFive;
        mt_srand(2201);
        $expectedNext = mt_rand();
        mt_srand(2201);
        $this->roles()->applyForExecution($actor, $target, $state, $rankFive, $execution);

        $this->assertSame($expectedNext, mt_rand(), 'The route comparison must not consume RNG.');
        $this->assertSame('PHYSICAL_DAMAGE', $execution->effect_template);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('magic_aim_prep'), 'Preparation survives until the art actually executes.');
        $this->roles()->beginJobArtCast($actor, $state, $rankFive);
        $this->assertNull($actor->jobArtV2PreparedEffect('magic_aim_prep'));

        [$cross, $crossTarget, $crossState] = $this->battle(60);
        $crossLoad = clone $load;
        $crossLoad->setAttribute('id', ++$this->nextSkillId);
        $this->inherit($cross, $crossLoad);
        $this->cast($cross, $crossTarget, $crossState, $crossLoad);
        $this->assertNull($cross->jobArtV2PreparedEffect('magic_aim_prep'));
    }

    public function test_break_focus_trades_damage_for_resource_and_only_arms_against_defense(): void
    {
        [$actor, $target, $state] = $this->battle(68);
        $target->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        $focus = $this->art(33, 1, '練気', 'SELF_BUFF', 100, hitCount: 0);
        $this->inherit($actor, $focus);

        $execution = $this->cast($actor, $target, $state, $focus, HitResult::HIT, applyResource: true);

        $this->assertSame('V2_ROLE_EFFECT_ONLY', $execution->effect_template);
        $this->assertSame(0, (int) $execution->power);
        $this->assertSame(4, $actor->getResource('break'));
        $this->assertNotNull($actor->jobArtV2PreparedEffect('break_focus'));

        $rankFive = $this->art(68, 5, '雷冠閃拳', 'DAMAGE_BUFF', 285);
        $this->current($actor, $rankFive);
        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankFive);
        $this->assertSame(1_150, $this->roles()->modifyJobArtDamage($actor, $state, $rankFive, 1_000));
        $this->assertNull($actor->jobArtV2PreparedEffect('break_focus'));

        [$cross, $crossTarget, $crossState] = $this->battle(65);
        $crossFocus = clone $focus;
        $crossFocus->setAttribute('id', ++$this->nextSkillId);
        $this->inherit($cross, $crossFocus);
        $crossExecution = $this->cast($cross, $crossTarget, $crossState, $crossFocus, applyResource: true);
        $this->assertSame('SELF_BUFF', $crossExecution->effect_template);
        $this->assertSame(0, $cross->getResource('break'));
        $this->assertNull($cross->jobArtV2PreparedEffect('break_focus'));
    }

    public function test_split_pierce_reduces_exactly_two_rank_five_costs_and_never_rank_nine(): void
    {
        [$actor, $target, $state] = $this->battle(62);
        $breath = $this->art(98, 1, '蒼竜の息吹', 'PHYSICAL_DAMAGE', 225);
        $this->inherit($actor, $breath);
        $this->cast($actor, $target, $state, $breath);

        $prepared = $actor->jobArtV2PreparedEffect('split_pierce');
        $this->assertNotNull($prepared);
        $this->assertSame([2, 5], [$prepared->charges, $prepared->remainingActionOpportunities]);

        $rankFive = $this->art(62, 5, '竜冠穿槍', 'PHYSICAL_DAMAGE', 285);
        $this->current($actor, $rankFive);
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 4);
        foreach ([2, 0] as $expectedResource) {
            $this->beginAction($actor, $state);
            $this->assertNull($this->resources()->eligibilityBlockReason($actor, $rankFive, $state));
            $result = $this->resources()->applyJobArtCast($actor, $state, $rankFive);
            $this->assertSame(-2, $result->delta);
            $this->roles()->beginJobArtCast($actor, $state, $rankFive);
            $this->assertSame($expectedResource, $actor->getResource('dragon_force'));
        }
        $this->assertNull($actor->jobArtV2PreparedEffect('split_pierce'));

        $rankNine = $this->art(62, 9, '竜冠天穿槍', 'PHYSICAL_DAMAGE', 355);
        $this->current($actor, $rankNine);
        $actor->setResource('dragon_force', 11);
        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $this->resources()->eligibilityBlockReason($actor, $rankNine, $state));
    }

    public function test_super_pierce_stance_controls_rank_nine_penetration_and_damage_snapshot(): void
    {
        [$actor, $target, $state] = $this->battle(52, targetOverrides: ['def' => 1_000]);
        $rankOne = $this->art(52, 1, '蒼天槍', 'PHYSICAL_DAMAGE', 225);
        $rankNine = $this->art(52, 9, '蒼穹ドラグーンダイブ', 'PHYSICAL_DAMAGE', 355);
        $this->current($actor, $rankOne);
        $this->current($actor, $rankNine);
        $state->turnCount = 1;
        $this->cast($actor, $target, $state, $rankOne);

        $this->roles()->endRound($state);
        $this->assertTrue($actor->jobArtV2ProgressionState()->hasRoundState('super_pierce_stance'));
        $state->turnCount = 2;
        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankNine);

        $this->assertSame(1_150, $this->roles()->modifyJobArtDamage($actor, $state, $rankNine, 1_000));
        $this->assertSame(0.50, app(JobArtV2PenetrationService::class)->defenseOverrides($actor, $target, $rankNine)['penetration_rate']);
        $this->roles()->completeJobArtCast($actor, $target, $state, $rankNine, HitResult::HIT);
        $this->assertFalse($actor->jobArtV2ProgressionState()->hasRoundState('super_pierce_stance'));

        [$plain, $plainTarget, $plainState] = $this->battle(52, targetOverrides: ['def' => 1_000]);
        $plainRankNine = clone $rankNine;
        $plainRankNine->setAttribute('id', ++$this->nextSkillId);
        $this->current($plain, $plainRankNine);
        $this->beginAction($plain, $plainState);
        $this->roles()->beginJobArtCast($plain, $plainState, $plainRankNine);
        $this->assertSame(1_000, $this->roles()->modifyJobArtDamage($plain, $plainState, $plainRankNine, 1_000));
        $this->assertNull(app(JobArtV2PenetrationService::class)->defenseOverrides($plain, $plainTarget, $plainRankNine)['penetration_rate']);
    }

    public function test_hunt_super_uses_pre_mark_opener_and_successful_seal_finisher_branch(): void
    {
        [$actor, $target, $state] = $this->battle(54);
        $rankOne = $this->art(54, 1, '影糸仕込み', 'PHYSICAL_DAMAGE', 205);
        $rankFive = $this->art(54, 5, '影縫い乱舞', 'PHYSICAL_DAMAGE', 255);
        $rankNine = $this->art(54, 9, '影牢・無明縛', 'PHYSICAL_DAMAGE', 355);
        foreach ([$rankOne, $rankFive, $rankNine] as $skill) {
            $this->current($actor, $skill);
        }

        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankOne);
        $this->assertSame(1_150, $this->roles()->modifyJobArtDamage($actor, $state, $rankOne, 1_000));
        $this->roles()->completeJobArtCast($actor, $target, $state, $rankOne, HitResult::HIT);

        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankOne);
        $this->assertSame(1_000, $this->roles()->modifyJobArtDamage($actor, $state, $rankOne, 1_000));
        $this->roles()->completeJobArtCast($actor, $target, $state, $rankOne, HitResult::HIT);

        $target->jobArtV2ProgressionState()->lastActionCategory = 'buff';
        $this->cast($actor, $target, $state, $rankFive);
        $sealed = $this->art(66, 1, '封じられる加護', 'SELF_BUFF', 100);
        $this->assertTrue(app(JobArtV2ProgressionService::class)->consumeSealIfBlocked($target, $state, $sealed));
        $this->assertTrue($actor->jobArtV2ProgressionState()->huntRankFiveSealSucceeded);

        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankNine);
        $this->assertSame(1_200, $this->roles()->modifyJobArtDamage($actor, $state, $rankNine, 1_000));

        [$missed, $missedTarget, $missedState] = $this->battle(54);
        $missedRankNine = clone $rankNine;
        $missedRankNine->setAttribute('id', ++$this->nextSkillId);
        $this->current($missed, $missedRankNine);
        $this->beginAction($missed, $missedState);
        $this->roles()->beginJobArtCast($missed, $missedState, $missedRankNine);
        $this->assertSame(800, $this->roles()->modifyJobArtDamage($missed, $missedState, $missedRankNine, 1_000));
    }

    public function test_transmute_compensation_refunds_two_only_after_two_target_actions_without_gain(): void
    {
        [$actor, $target, $state] = $this->battle(67, targetJobId: 69);
        $rankOne = $this->art(67, 1, '金冠錬符', 'MAGICAL_DAMAGE_REWARD', 225);
        $rankFive = $this->art(67, 5, '金冠錬成', 'MAGICAL_DAMAGE_REWARD', 285);
        $this->current($actor, $rankOne);
        $this->current($actor, $rankFive);
        $actor->configureResource('catalyst', 12);
        $actor->setResource('catalyst', 8);
        $this->cast($actor, $target, $state, $rankOne, applyResource: true);
        $this->cast($actor, $target, $state, $rankFive, applyResource: true);
        $this->assertSame(0, $actor->getResource('catalyst'));

        foreach ([1, 2] as $action) {
            $this->beginAction($target, $state);
            $this->resources()->finishAction($target, $state);
            $this->resources()->finishAction($target, $state);
            $this->assertSame($action === 1 ? 0 : 2, $actor->getResource('catalyst'));
        }

        [$gainOwner, $gainTarget, $gainState] = $this->battle(67, targetJobId: 69);
        $gainR1 = clone $rankOne;
        $gainR1->setAttribute('id', ++$this->nextSkillId);
        $gainR5 = clone $rankFive;
        $gainR5->setAttribute('id', ++$this->nextSkillId);
        $this->current($gainOwner, $gainR1);
        $this->current($gainOwner, $gainR5);
        $gainOwner->configureResource('catalyst', 12);
        $gainOwner->setResource('catalyst', 8);
        $this->cast($gainOwner, $gainTarget, $gainState, $gainR1, applyResource: true);
        $this->cast($gainOwner, $gainTarget, $gainState, $gainR5, applyResource: true);
        $gainTarget->configureResource('command_points', 12);
        $this->beginAction($gainTarget, $gainState);
        $normal = $this->resources()->recordNormalAttackResolution($gainTarget, $gainOwner, $gainState, HitResult::HIT);
        $this->assertSame(2, $normal->delta, 'The actual +4 gain is halved once.');
        $this->resources()->finishAction($gainTarget, $gainState);
        $this->assertSame(0, $gainOwner->getResource('catalyst'));
    }

    public function test_break_super_rank_five_uses_shared_three_hit_split_and_rank_nine_snapshots_low_hp(): void
    {
        [$actor, $target, $state] = $this->battle(58, actorOverrides: ['hp' => 350, 'max_hp' => 1_000]);
        $rankFive = $this->art(58, 5, '雷拳乱舞', 'PHYSICAL_DAMAGE', 255);
        $rankNine = $this->art(58, 9, '雷霆覇王拳', 'PHYSICAL_DAMAGE', 355);
        $rankOne = $this->art(58, 1, '雷気充填', 'PHYSICAL_DAMAGE', 205);
        foreach ([$rankOne, $rankFive, $rankNine] as $skill) {
            $this->current($actor, $skill);
        }

        $execution = $this->cast($actor, $target, $state, $rankFive);
        $this->assertSame('MULTI_HIT', $execution->effect_template);
        $this->assertSame(3, $execution->hit_count);
        $this->assertSame([85, 85, 85], JobArtHitPower::split((int) $execution->power, (int) $execution->hit_count));
        $this->assertSame(1, array_sum($target->jobArtV2ProgressionState()->breakMarks));

        for ($i = 0; $i < 2; $i++) {
            $this->cast($actor, $target, $state, $rankOne);
        }
        $this->beginAction($actor, $state);
        $this->roles()->beginJobArtCast($actor, $state, $rankNine);
        $actor->hp = 900;
        $this->assertSame(1_200, $this->roles()->modifyJobArtDamage($actor, $state, $rankNine, 1_000));
    }

    public function test_command_crown_rank_one_has_exact_cooldown_and_disadvantaged_only_reroll(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $rankOne = $this->art(69, 1, '戦冠指揮', 'PHYSICAL_DAMAGE', 225);
        $this->current($actor, $rankOne);
        $state->turnCount = 5;
        $this->cast($actor, $target, $state, $rankOne, applyResource: true);
        $this->assertSame(0, $actor->getResource('command_points'));

        $progression = app(JobArtV2ProgressionService::class);
        foreach ([6, 7] as $round) {
            $state->turnCount = $round;
            $this->assertSame('blocked_by_internal_cooldown', $progression->eligibilityBlockReason($actor, $state, $rankOne));
        }
        $state->turnCount = 8;
        $this->assertNull($progression->eligibilityBlockReason($actor, $state, $rankOne));

        [$missActor, $missTarget, $missState] = $this->battle(69);
        $missRankOne = $this->art(69, 1, '戦冠指揮', 'PHYSICAL_DAMAGE', 225);
        $this->current($missActor, $missRankOne);
        $missState->turnCount = 5;
        $this->cast($missActor, $missTarget, $missState, $missRankOne, HitResult::MISS);
        $this->assertTrue($missActor->jobArtV2ProgressionState()->initiativeRerollNextRound);
        $this->assertSame(8, $missActor->jobArtV2ProgressionState()->commandRankOneCooldownUntilRound);

        $calls = 0;
        $this->assertTrue($progression->adjustInitiative($actor, $target, true, function () use (&$calls): bool {
            $calls++;
            return false;
        }));
        $this->assertSame(0, $calls, 'An already-first actor must not reroll.');

        $actor->jobArtV2ProgressionState()->initiativeRerollNextRound = true;
        $this->assertFalse($progression->adjustInitiative($actor, $target, false, function () use (&$calls): bool {
            $calls++;
            return false;
        }));
        $this->assertSame(1, $calls);
        $this->assertFalse($actor->jobArtV2ProgressionState()->initiativeRerollNextRound);

        $actor->jobArtV2ProgressionState()->initiativeForceFirstNextRound = true;
        $this->assertTrue($progression->adjustInitiative($actor, $target, false, fn (): bool => false));
    }

    public function test_progression_display_and_flag_off_are_fail_closed(): void
    {
        $rankFive = $this->art(58, 5, '雷拳乱舞', 'PHYSICAL_DAMAGE', 255);
        $rankFive->setAttribute('job_art_origin', 'current');
        $presented = app(JobArtV2LoadoutPresenter::class)->forArt(58, $rankFive);
        $this->assertSame(['MULTI_HIT', 3], [$presented['effect_template'], $presented['effective_hit_count']]);
        $this->assertContains('3Hit（master総威力を均等分割）', $presented['effect_texts']);

        $rankFive->setAttribute('job_art_origin', 'inherited');
        $cross = app(JobArtV2LoadoutPresenter::class)->forArt(65, $rankFive);
        $this->assertSame(['PHYSICAL_DAMAGE', 1], [$cross['effect_template'], $cross['effective_hit_count']]);

        config(['battle.job_art_v2.resources' => false]);
        [$actor, $target, $state] = $this->battle(58);
        $this->current($actor, $rankFive);
        $before = serialize($actor);
        mt_srand(5809);
        $expected = mt_rand();
        mt_srand(5809);
        $sourceActionId = $state->beginSourceAction();
        $this->roles()->beginAction($actor, $state, $sourceActionId);
        $execution = clone $rankFive;
        $this->roles()->applyForExecution($actor, $target, $state, $rankFive, $execution);
        $this->roles()->beginJobArtCast($actor, $state, $rankFive);
        $this->roles()->completeJobArtCast($actor, $target, $state, $rankFive, HitResult::HIT);
        $this->assertSame($before, serialize($actor));
        $this->assertSame($expected, mt_rand());
        $this->assertSame(['PHYSICAL_DAMAGE', 1], [$execution->effect_template, $execution->hit_count]);
    }

    public function test_all_six_restored_super_jobs_report_full_v2_coverage(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);
        foreach ([52, 54, 55, 57, 58, 59] as $jobId) {
            $this->assertSame('full_v2_effect', $catalog->effectCoverageForCurrentJob($jobId), (string) $jobId);
        }
    }

    public function test_hud_exposes_fix_now_battle_memory_without_persisting_it(): void
    {
        [$actor, $target, $state] = $this->battle(54);
        $rankOne = $this->art(54, 1, '影糸仕込み', 'PHYSICAL_DAMAGE', 205);
        $this->current($actor, $rankOne);
        $this->cast($actor, $target, $state, $rankOne);

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $this->assertNotNull($hud);
        $this->assertContains('狩猟印：1/3', $hud['actors'][0]['progression']);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(
        int $actorJobId,
        int $targetJobId = 60,
        array $actorOverrides = [],
        array $targetOverrides = [],
    ): array {
        $actor = $this->actor('actor', true, $actorJobId, $actorOverrides);
        $target = $this->actor('target', false, $targetJobId, $targetOverrides);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(string $name, bool $isPlayer, int $jobId, array $overrides = []): BattleActor
    {
        return new BattleActor($name, $isPlayer, array_replace([
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 250,
            'def' => 100,
            'agi' => 100,
            'mag' => 250,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ], $overrides));
    }

    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $template,
        int $power,
        int $hitCount = 1,
        array $attributes = [],
    ): Skill {
        $skill = new Skill(array_replace([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => str_contains($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => $hitCount,
            'activation_rate' => 100,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ], $attributes));
        $skill->setAttribute('id', ++$this->nextSkillId);

        return $skill;
    }

    private function current(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function inherit(BattleActor $actor, Skill $skill, float $rate = 1.0): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = $rate;
    }

    private function beginAction(BattleActor $actor, BattleState $state): int
    {
        $sourceActionId = $this->resources()->beginAction($actor, $state);
        $this->assertNotNull($sourceActionId);
        $this->roles()->beginAction($actor, $state, $sourceActionId);

        return $sourceActionId;
    }

    private function qualifyingMitigation(
        BattleActor $actor,
        BattleActor $attacker,
        BattleState $state,
    ): int {
        app(JobArtV2DefenseService::class)->applyGuard($actor, $state, 0.20);
        $sourceActionId = $this->beginAction($attacker, $state);

        return app(JobArtV2DefenseService::class)->resolveDamage(
            $state,
            new DirectAttackResolution(
                sourceActionId: $sourceActionId,
                attacker: $attacker,
                target: $actor,
                hitResult: HitResult::HIT,
                damageCategory: 'physical',
                direct: true,
                actionType: BattleActionType::NORMAL_ATTACK,
            ),
            100,
        );
    }

    private function cast(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $source,
        ?HitResult $hitResult = HitResult::HIT,
        bool $applyResource = false,
    ): Skill {
        $this->beginAction($actor, $state);
        $execution = clone $source;
        $this->roles()->applyForExecution($actor, $target, $state, $source, $execution);
        if ($applyResource) {
            $this->resources()->applyJobArtCast($actor, $state, $source);
        }
        $this->roles()->beginJobArtCast($actor, $state, $source);
        $this->roles()->completeJobArtCast($actor, $target, $state, $source, $hitResult);

        return $execution;
    }

    private function roles(): JobArtV2RoleEffectService
    {
        return app(JobArtV2RoleEffectService::class);
    }

    private function resources(): JobArtV2ResourceService
    {
        return app(JobArtV2ResourceService::class);
    }
}
