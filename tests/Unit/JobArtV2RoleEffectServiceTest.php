<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2PreparedEffectState;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2TimedEffectState;
use Tests\TestCase;

final class JobArtV2RoleEffectServiceTest extends TestCase
{
    private int $nextSkillId = 90_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableV2();
    }

    public function test_timed_effect_refreshes_without_stacking_and_uses_the_frozen_round_lifecycle(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $art = $this->art(11, 1, '納刀', 'DAMAGE_BUFF', 100, 1);

        $state->turnCount = 1;
        $this->cast($service, $actor, $target, $state, $art);

        $effect = $actor->jobArtV2TimedEffect('counter_sheathed_tempo');
        $this->assertNotNull($effect);
        $this->assertSame(['str' => 0.05], $effect->statModifiers);
        $this->assertSame(105, $actor->effectiveStr());
        $this->assertSame(80, $actor->effectiveDef());
        $this->assertSame(2, $effect->remainingRounds);

        $service->endRound($state);
        $this->assertSame(2, $effect->remainingRounds, 'The application round must not decrement duration.');

        $state->turnCount = 2;
        $this->cast($service, $actor, $target, $state, $art);
        $refreshed = $actor->jobArtV2TimedEffect('counter_sheathed_tempo');
        $this->assertNotSame($effect, $refreshed);
        $this->assertCount(1, $actor->jobArtV2TimedEffects());
        $this->assertSame(2, $refreshed?->remainingRounds);
        $service->endRound($state);
        $this->assertSame(2, $refreshed?->remainingRounds);

        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(1, $refreshed?->remainingRounds);
        $this->assertSame(105, $actor->effectiveStr());

        $state->turnCount = 4;
        $service->endRound($state);
        $this->assertNull($actor->jobArtV2TimedEffect('counter_sheathed_tempo'));
        $this->assertSame(100, $actor->effectiveStr());
    }

    public function test_counter_focus_is_consumed_on_actual_rank_five_or_nine_execution_for_every_hit_result(): void
    {
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(60);
            $service = $this->service();
            $focus = $this->art(28, 1, '剣気集中', 'SELF_BUFF', 100, 0);
            $finisher = $this->art(11, 9, 'test-counter-finisher', 'PHYSICAL_DAMAGE', 255, 1);

            $state->turnCount = 1;
            $this->cast($service, $actor, $target, $state, $focus);
            $this->assertNotNull($actor->jobArtV2PreparedEffect('counter_focus'));

            $this->beginAction($service, $actor, $state);
            $service->beginJobArtCast($actor, $state, $finisher);
            $prepared = $actor->jobArtV2PreparedEffect('counter_focus');
            $this->assertNotNull($prepared, "counter_focus spent both charges for {$hitResult->value}");
            $this->assertSame(1, $prepared->charges);
            $this->assertSame(5, $prepared->remainingActionOpportunities);
            $this->assertSame(1_200, $service->modifyJobArtDamage($actor, $state, $finisher, 1_000));
            $service->completeJobArtCast($actor, $target, $state, $finisher, $hitResult);

            $this->beginAction($service, $actor, $state);
            $service->beginJobArtCast($actor, $state, $finisher);
            $this->assertNull($actor->jobArtV2PreparedEffect('counter_focus'));
            $this->assertSame(1_200, $service->modifyJobArtDamage($actor, $state, $finisher, 1_000));
        }
    }

    public function test_counter_focus_counts_at_most_six_actual_own_action_opportunities(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $focus = $this->art(28, 1, '剣気集中', 'SELF_BUFF', 100, 0);
        $otherLineage = $this->art(61, 5, 'other-lineage-rank-five', 'MAGICAL_DAMAGE', 285, 1);
        $counterRankNine = $this->art(11, 9, 'counter-rank-nine', 'PHYSICAL_DAMAGE', 255, 1);

        $state->turnCount = 1;
        $this->cast($service, $actor, $target, $state, $focus);
        $prepared = $actor->jobArtV2PreparedEffect('counter_focus');
        $this->assertNotNull($prepared);
        $this->assertNull($prepared->remainingRounds, 'counter_focus must not use round expiry.');
        $this->assertSame(6, $prepared->remainingActionOpportunities);

        $state->turnCount = 20;
        $service->endRound($state);
        $this->assertSame(6, $prepared->remainingActionOpportunities, 'Rounds and the creation action do not count.');

        // A failed activation does not execute the Job Art and therefore does
        // not consume its charge. The actual fallback normal action counts once.
        $this->beginAction($service, $actor, $state);
        $this->assertSame(6, $prepared->remainingActionOpportunities);
        $service->markNonJobArtAction($actor, $state);
        $service->markNonJobArtAction($actor, $state);
        $this->assertSame(5, $prepared->remainingActionOpportunities, 'One source action may only count once.');
        $this->assertSame(2, $prepared->charges);

        // Other-lineage Job Arts count as opportunities but do not consume the
        // prepared charge or multiplier.
        for ($i = 0; $i < 3; $i++) {
            $this->beginAction($service, $actor, $state);
            $service->beginJobArtCast($actor, $state, $otherLineage);
        }
        $this->assertSame(2, $prepared->remainingActionOpportunities);
        $this->assertSame(2, $prepared->charges);

        // The fifth and sixth opportunities may both be triggers. Each actual
        // execution consumes one charge before HIT/MISS/EVADE resolution.
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $counterRankNine);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('counter_focus'));
        $this->assertSame(1, $prepared->remainingActionOpportunities);
        $this->assertSame(1, $prepared->charges);
        $this->assertSame(1_200, $service->modifyJobArtDamage($actor, $state, $counterRankNine, 1_000));

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $counterRankNine);
        $this->assertNull($actor->jobArtV2PreparedEffect('counter_focus'));
        $this->assertSame(1_200, $service->modifyJobArtDamage($actor, $state, $counterRankNine, 1_000));
    }

    public function test_counter_focus_expires_after_six_non_trigger_own_actions(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $focus = $this->art(28, 1, '剣気集中', 'SELF_BUFF', 100, 0);

        $this->cast($service, $actor, $target, $state, $focus);
        for ($i = 1; $i <= 6; $i++) {
            $this->beginAction($service, $actor, $state);
            $service->markNonJobArtAction($actor, $state);
            if ($i < 6) {
                $this->assertSame(6 - $i, $actor->jobArtV2PreparedEffect('counter_focus')?->remainingActionOpportunities);
            }
        }

        $this->assertNull($actor->jobArtV2PreparedEffect('counter_focus'));
    }

    public function test_light_wing_cross_break_consumes_counter_focus_without_receiving_its_multiplier(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $focus = $this->art(28, 1, '剣気集中', 'SELF_BUFF', 100, 0);
        $lightWing = $this->art(50, 9, '光翼クロスブレイク', 'DAMAGE_BUFF', 320, 1);

        $this->cast($service, $actor, $target, $state, $focus);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('counter_focus'));

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $lightWing);

        $prepared = $actor->jobArtV2PreparedEffect('counter_focus');
        $this->assertNotNull($prepared);
        $this->assertSame(1, $prepared->charges);
        $this->assertSame(5, $prepared->remainingActionOpportunities);
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $lightWing, 1_000));
        $service->completeJobArtCast($actor, $target, $state, $lightWing, HitResult::HIT);
        $this->assertNotNull($actor->jobArtV2TimedEffect('counter_light_wing_guard'));
    }

    public function test_strict_preparation_expires_on_the_next_non_trigger_action_but_flexible_preparation_survives(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $strict = $this->art(2, 1, '挑発撃', 'DAMAGE_BUFF', 90, 1);
        $flexible = $this->art(16, 1, '実戦勘', 'DAMAGE_BUFF', 100, 1);

        $this->cast($service, $actor, $target, $state, $strict);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));
        $service->markNonJobArtAction($actor);
        $this->assertNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));

        $this->cast($service, $actor, $target, $state, $flexible);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));
        $service->markNonJobArtAction($actor);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));

        $wrongLineage = $this->art(11, 5, 'counter-rank-five', 'PHYSICAL_DAMAGE', 165, 1);
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $wrongLineage);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));

        $pierceRankFive = $this->art(2, 5, 'pierce-rank-five', 'PHYSICAL_DAMAGE', 145, 1);
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $pierceRankFive);
        $this->assertNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));
        $this->assertSame(1_100, $service->modifyJobArtDamage($actor, $state, $pierceRankFive, 1_000));
    }

    public function test_colosseum_multiplier_uses_and_consumes_the_physical_received_window(): void
    {
        [$actor, , $state] = $this->battle(60);
        $service = $this->service();
        $art = $this->art(13, 9, 'コロッセオブレイク', 'DAMAGE_BUFF', 255, 1);

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $art);
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $art, 1_000));

        $service->recordPhysicalAttackReceived($actor);
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $art);
        $this->assertTrue((bool) ($state->jobArtV2RoleAction()['conditional_multiplier_applied'] ?? false));
        $this->assertSame(1_150, $service->modifyJobArtDamage($actor, $state, $art, 1_000));

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $art);
        $this->assertFalse((bool) ($state->jobArtV2RoleAction()['conditional_multiplier_applied'] ?? true));
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $art, 1_000));
    }

    public function test_adaptive_route_is_rng_free_includes_pve_physical_critical_expectation_and_uses_master_route_for_external_tie(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $art = $this->art(22, 5, 'エレメントアロー', 'MAGICAL_DAMAGE', 165, 1, [
            'damage_type' => 'magical',
        ]);
        $execution = clone $art;

        $this->beginAction($service, $actor, $state);
        mt_srand(22_505);
        $expected = mt_rand();
        mt_srand(22_505);
        $service->applyForExecution($actor, $target, $state, $art, $execution);

        $this->assertSame($expected, mt_rand());
        $this->assertSame('PHYSICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('physical', $execution->damage_type);
        $this->assertSame('physical', $execution->getAttribute('job_art_v2_adaptive_route'));

        [$pvpActor, $pvpTarget, $pvpState] = $this->battle(60, 'pvp');
        $pvpExecution = clone $art;
        $this->beginAction($service, $pvpActor, $pvpState);
        $service->applyForExecution($pvpActor, $pvpTarget, $pvpState, $art, $pvpExecution);
        $this->assertSame('MAGICAL_DAMAGE', $pvpExecution->effect_template);
        $this->assertSame('magical', $pvpExecution->getAttribute('job_art_v2_adaptive_route'));

        $actor->str = 500;
        $actor->mag = 50;
        $physicalExecution = clone $art;
        $service->applyForExecution($actor, $target, $state, $art, $physicalExecution);
        $this->assertSame('PHYSICAL_DAMAGE', $physicalExecution->effect_template);
        $this->assertSame('physical', $physicalExecution->damage_type);
    }

    public function test_suppressed_execution_clears_legacy_side_effects_but_preserves_power_and_hit_count(): void
    {
        [$actor, $target, $state] = $this->battle(61);
        $service = $this->service();
        $source = $this->art(14, 1, '血潮の咆哮', 'SELF_BUFF', 123, 3, [
            'heal_percent' => 12,
            'mp_recover_percent' => 8,
            'self_damage_percent' => 9,
            'damage_reduction_percent' => 7,
            'self_buff_percent' => 6,
            'enemy_atk_down_percent' => 5,
            'enemy_mag_down_percent' => 4,
            'enemy_def_down_percent' => 3,
            'enemy_spr_down_percent' => 2,
            'enemy_spd_down_percent' => 1,
            'def_ignore_percent' => 11,
            'gold_bonus_percent' => 10,
            'drop_bonus_percent' => 9,
            'rare_bonus_percent' => 8,
            'drain_hp_rate' => 0.30,
            'reward_scope' => 'mixed',
        ]);
        $source->setAttribute('material_bonus_percent', 7);
        $execution = clone $source;

        $this->beginAction($service, $actor, $state);
        $service->applyForExecution($actor, $target, $state, $source, $execution);

        $this->assertSame('V2_ROLE_EFFECT_ONLY', $execution->effect_template);
        $this->assertSame(123, $execution->power);
        $this->assertSame(3, $execution->hit_count);
        foreach ([
            'heal_percent', 'mp_recover_percent', 'self_damage_percent',
            'damage_reduction_percent', 'self_buff_percent', 'enemy_atk_down_percent',
            'enemy_mag_down_percent', 'enemy_def_down_percent', 'enemy_spr_down_percent',
            'enemy_spd_down_percent', 'def_ignore_percent', 'gold_bonus_percent',
            'drop_bonus_percent', 'rare_bonus_percent',
        ] as $field) {
            $this->assertSame(0, (int) $execution->getAttribute($field), $field);
        }
        $this->assertSame(0, (int) $execution->getAttribute('material_bonus_percent'));
        $this->assertSame(0.0, (float) $execution->drain_hp_rate);
        $this->assertSame('none', $execution->reward_scope);
    }

    public function test_blood_cost_is_non_lethal_and_reports_the_real_self_damage_event(): void
    {
        [$actor, $target, $state] = $this->battle(61, actorOverrides: ['hp' => 2, 'max_hp' => 100]);
        $service = $this->service();
        $art = $this->art(14, 1, '血潮の咆哮', 'SELF_BUFF', 100, 0);

        $this->cast($service, $actor, $target, $state, $art);

        $this->assertSame(1, $actor->hp);
        $this->assertSame(1, $actor->totalDamageTaken);
        $this->assertSame(2, $actor->getResource('eclipse'));
        $this->assertSame(130, $actor->effectiveStr());
        $this->assertSame(125, $actor->effectiveMag());
    }

    public function test_role_heal_cleanse_and_guard_use_the_shared_services(): void
    {
        [$guard, $target, $guardState] = $this->battle(66, actorOverrides: [
            'hp' => 100,
            'max_hp' => 200,
            'spr' => 100,
        ]);
        $service = $this->service();
        $prayer = $this->art(36, 1, '聖戦の祈り', 'HEAL', 80, 0, ['heal_percent' => 30]);
        $guard->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'spr-heal-test',
            statModifiers: ['spr' => 0.25],
            appliedRound: 0,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 25,
        ));

        $this->cast($service, $guard, $target, $guardState, $prayer);
        // effective SPR 125 * power 80% * prayer multiplier 70% = 70.
        $this->assertSame(170, $guard->hp);
        $this->assertSame(0, (int) $guardState->jobArtV2RoleAction()['execution_power'] - 80);
        $this->assertSame(0.15, $guard->jobArtV2GuardState()?->rate);
        $this->assertSame(1, $guard->jobArtV2GuardState()?->charges);

        [$medicineUser, $medicineTarget, $medicineState] = $this->battle(67, actorOverrides: [
            'hp' => 100,
            'max_hp' => 200,
        ]);
        $medicineUser->conditions = [
            'burn' => ['rate' => 0.10, 'remaining_turns' => 2],
            'poison' => ['rate' => 0.10, 'remaining_turns' => 2],
        ];
        $medicine = $this->art(47, 1, '聖薬散布', 'REWARD_MIXED', 175, 0, [
            'gold_bonus_percent' => 9,
            'drop_bonus_percent' => 9,
        ]);

        $this->cast($service, $medicineUser, $medicineTarget, $medicineState, $medicine);
        $this->assertSame(110, $medicineUser->hp);
        $this->assertArrayNotHasKey('burn', $medicineUser->conditions);
        $this->assertArrayHasKey('poison', $medicineUser->conditions);
        $this->assertSame(['burn'], $medicineState->cleanseResults()[0]->removedStates);
    }

    public function test_potion_mix_cleanses_only_the_first_priority_state(): void
    {
        [$actor, $target, $state] = $this->battle(67);
        $actor->conditions = [
            'slow' => ['rate' => 0.10, 'remaining_turns' => 2],
            'poison' => ['rate' => 0.10, 'remaining_turns' => 2],
            'burn' => ['rate' => 0.10, 'remaining_turns' => 2],
        ];
        $service = $this->service();
        $potion = $this->art(25, 5, '秘薬調合', 'HEAL_CLEANSE', 100, 0, [
            'mp_recover_percent' => 10,
        ]);

        $this->cast($service, $actor, $target, $state, $potion);

        $this->assertArrayNotHasKey('burn', $actor->conditions);
        $this->assertArrayHasKey('poison', $actor->conditions);
        $this->assertArrayHasKey('slow', $actor->conditions);
        $this->assertSame(['burn'], $state->cleanseResults()[0]->removedStates);
    }

    public function test_king_elixir_boosts_only_the_lower_resource_ratio_and_prefers_hp_on_tie(): void
    {
        $service = $this->service();
        $source = $this->art(38, 5, '王者の秘薬', 'HEAL', 100, 0, [
            'mp_recover_percent' => 10,
        ]);

        foreach ([
            'hp lower' => [['hp' => 20, 'max_hp' => 100, 'mp' => 80, 'max_mp' => 100], 'hp', 150, 10],
            'sp lower' => [['hp' => 80, 'max_hp' => 100, 'mp' => 20, 'max_mp' => 100], 'sp', 100, 15],
            'tie prefers hp' => [['hp' => 50, 'max_hp' => 100, 'mp' => 50, 'max_mp' => 100], 'hp', 150, 10],
        ] as $case => [$overrides, $expectedTarget, $expectedPower, $expectedSpRate]) {
            [$actor, $target, $state] = $this->battle(67, actorOverrides: $overrides);
            $this->beginAction($service, $actor, $state);
            $execution = clone $source;

            $service->applyForExecution($actor, $target, $state, $source, $execution);

            $this->assertSame($expectedTarget, $execution->getAttribute('job_art_v2_adaptive_sustain_target'), $case);
            $this->assertSame($expectedPower, (int) $execution->power, $case);
            $this->assertSame($expectedSpRate, (int) $execution->mp_recover_percent, $case);
        }

        $this->assertSame(100, (int) $source->power, 'The master/source Skill must remain unchanged.');
        $this->assertSame(10, (int) $source->mp_recover_percent);
    }

    public function test_reward_axes_are_kept_separate_for_gold_and_drop_specialists(): void
    {
        [$actor, $target, $state] = $this->battle(67);
        $service = $this->service();
        $gold = $this->art(8, 5, '幸運の一手', 'REWARD_GOLD', 145, 0, [
            'gold_bonus_percent' => 7,
            'drop_bonus_percent' => 9,
            'rare_bonus_percent' => 4,
            'reward_scope' => 'normal_exploration_win_only',
        ]);
        $goldExecution = clone $gold;
        $this->beginAction($service, $actor, $state);
        $service->applyForExecution($actor, $target, $state, $gold, $goldExecution);

        $this->assertSame('REWARD_GOLD', $goldExecution->effect_template);
        $this->assertSame(7, (int) $goldExecution->gold_bonus_percent);
        $this->assertSame(0, (int) $goldExecution->drop_bonus_percent);
        $this->assertSame(0, (int) $goldExecution->rare_bonus_percent);

        [$dropActor, $dropTarget, $dropState] = $this->battle(67);
        $drop = $this->art(20, 5, '掘り出し物', 'REWARD_MIXED', 165, 0, [
            'gold_bonus_percent' => 12,
            'drop_bonus_percent' => 6,
            'rare_bonus_percent' => 3,
            'reward_scope' => 'normal_exploration_win_only',
        ]);
        $dropExecution = clone $drop;
        $this->beginAction($service, $dropActor, $dropState);
        $service->applyForExecution($dropActor, $dropTarget, $dropState, $drop, $dropExecution);

        $this->assertSame('V2_ROLE_EFFECT_ONLY', $dropExecution->effect_template);
        $this->assertSame(0, $dropState->goldBonusPercent);
        $this->assertSame(6, $dropState->dropBonusPercent);
        $this->assertSame(3, $dropState->rareBonusPercent);
        $this->assertSame(0, (int) $dropExecution->gold_bonus_percent);
        $this->assertSame(0, (int) $dropExecution->drop_bonus_percent);
    }

    public function test_harvest_removes_only_the_strongest_removable_timed_effect(): void
    {
        [$actor, $target, $state] = $this->battle(67);
        $service = $this->service();
        $target->replaceJobArtV2TimedEffect($this->timed('weak', 0.05, 5, true));
        $target->replaceJobArtV2TimedEffect($this->timed('strong', 0.15, 15, true));
        $target->replaceJobArtV2TimedEffect($this->timed('locked', 0.50, 50, false));
        $harvest = $this->art(38, 9, '富国の錬金陣', 'REWARD_MIXED', 315, 0);

        $this->cast($service, $actor, $target, $state, $harvest);

        $this->assertNotNull($target->jobArtV2TimedEffect('weak'));
        $this->assertNull($target->jobArtV2TimedEffect('strong'));
        $this->assertNotNull($target->jobArtV2TimedEffect('locked'));
        $this->assertNotNull($actor->jobArtV2TimedEffect('transmute_harvested_power'));
    }

    public function test_golden_appraisal_marks_the_target_in_battle_memory(): void
    {
        [$actor, $target, $state] = $this->battle(67);
        $art = $this->art(31, 1, '黄金鑑定', 'REWARD_MIXED', 110, 0);

        $this->assertFalse($target->isJobArtV2Appraised());
        $this->cast($this->service(), $actor, $target, $state, $art);
        $this->assertTrue($target->isJobArtV2Appraised());
    }

    public function test_flag_off_keeps_actor_state_execution_clone_logs_and_rng_unchanged(): void
    {
        config(['battle.job_art_v2.resources' => false]);
        [$actor, $target, $state] = $this->battle(61, actorOverrides: ['hp' => 2, 'max_hp' => 100]);
        $service = $this->service();
        $source = $this->art(14, 1, '血潮の咆哮', 'SELF_BUFF', 100, 0, [
            'self_damage_percent' => 3,
            'self_buff_percent' => 10,
        ]);
        $execution = clone $source;
        $prepared = new JobArtV2PreparedEffectState(
            key: 'strict-before-off',
            multiplier: 1.15,
            appliedRound: 1,
            remainingRounds: null,
            charges: 1,
            sourceActionId: 1,
            sourceSkillId: 1,
            targetLineage: 'pierce',
            targetRanks: [5, 9],
            strictNextAction: true,
            group: 'off',
        );
        $actor->replaceJobArtV2PreparedEffect($prepared);
        $timed = $this->timed('timed-before-off', 0.20, 20, true);
        $actor->replaceJobArtV2TimedEffect($timed);

        $state->turnCount = 2;
        $sourceActionId = $state->beginSourceAction();
        mt_srand(61_001);
        $expected = mt_rand();
        mt_srand(61_001);
        $service->beginAction($actor, $state, $sourceActionId);
        $service->recordPhysicalAttackReceived($actor);
        $service->markNonJobArtAction($actor);
        $service->applyForExecution($actor, $target, $state, $source, $execution);
        $service->beginJobArtCast($actor, $state, $source);
        $service->completeJobArtCast($actor, $target, $state, $source, HitResult::HIT);
        $service->endRound($state);

        $this->assertSame($expected, mt_rand());
        $this->assertSame(2, $actor->hp);
        $this->assertSame(0, $actor->totalDamageTaken);
        $this->assertSame(0, $actor->getResource('eclipse'));
        $this->assertSame($source->getAttributes(), $execution->getAttributes());
        $this->assertSame($prepared, $actor->jobArtV2PreparedEffect('strict-before-off'));
        $this->assertSame(2, $timed->remainingRounds);
        $this->assertFalse($target->isJobArtV2Appraised());
        $this->assertFalse($actor->consumePhysicalAttackReceivedSinceOwnActionSnapshot());
        $this->assertSame([], $state->jobArtV2RoleAction());
        $this->assertSame([], $state->logs);
    }

    private function enableV2(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    private function service(): JobArtV2RoleEffectService
    {
        return app(JobArtV2RoleEffectService::class);
    }

    /**
     * @param array<string, int|string> $actorOverrides
     * @return array{BattleActor, BattleActor, BattleState}
     */
    private function battle(int $currentJobId, string $battleType = 'pve', array $actorOverrides = []): array
    {
        $actor = $this->actor('actor', true, $currentJobId, $actorOverrides);
        $target = $this->actor('target', false, 60, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'str' => 100,
            'def' => 80,
            'agi' => 100,
            'mag' => 100,
            'spr' => 80,
            'luk' => 100,
        ]);

        return [$actor, $target, new BattleState($actor, $target, $battleType)];
    }

    /** @param array<string, int|string> $overrides */
    private function actor(string $name, bool $isPlayer, int $jobId, array $overrides = []): BattleActor
    {
        return new BattleActor($name, $isPlayer, array_replace([
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 100,
            'def' => 80,
            'agi' => 100,
            'mag' => 100,
            'spr' => 80,
            'luk' => 100,
            'current_job_id' => $jobId,
        ], $overrides));
    }

    /**
     * @param array<string, int|float|string|bool> $attributes
     */
    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $template,
        int $power,
        int $hitCount,
        array $attributes = [],
    ): Skill {
        $skill = new Skill(array_replace([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $template === 'MAGICAL_DAMAGE' ? 'magical' : 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => $hitCount,
            'reward_scope' => 'none',
        ], $attributes));
        $skill->setAttribute('id', ++$this->nextSkillId);

        return $skill;
    }

    private function beginAction(
        JobArtV2RoleEffectService $service,
        BattleActor $actor,
        BattleState $state,
    ): int {
        $sourceActionId = $state->beginSourceAction();
        $service->beginAction($actor, $state, $sourceActionId);

        return $sourceActionId;
    }

    private function cast(
        JobArtV2RoleEffectService $service,
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $source,
        ?HitResult $hitResult = HitResult::HIT,
    ): Skill {
        $this->beginAction($service, $actor, $state);
        $execution = clone $source;
        $service->applyForExecution($actor, $target, $state, $source, $execution);
        $service->beginJobArtCast($actor, $state, $source);
        $service->completeJobArtCast($actor, $target, $state, $source, $hitResult);

        return $execution;
    }

    private function timed(string $key, float $rate, float $strength, bool $removable): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: ['str' => $rate],
            appliedRound: 1,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: $removable,
            strength: $strength,
        );
    }
}
