<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\ConversionResult;
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

        $effect = $actor->jobArtV2TimedEffect('canonical_self_buff:11:1');
        $this->assertNotNull($effect);
        $this->assertSame(['str' => 0.15], $effect->statModifiers);
        $this->assertSame(115, $actor->effectiveStr());
        $this->assertSame(80, $actor->effectiveDef());
        $this->assertSame(4, $effect->remainingRounds);

        $service->endRound($state);
        $this->assertSame(4, $effect->remainingRounds, 'The application round must not decrement duration.');

        $state->turnCount = 2;
        // 同じマスタ戦技が別のSkill IDで再読込されても、同名効果は別枠に積まず更新する。
        $sameMasterArt = $this->art(11, 1, '納刀', 'DAMAGE_BUFF', 100, 1);
        $this->assertNotSame((int) $art->id, (int) $sameMasterArt->id);
        $this->cast($service, $actor, $target, $state, $sameMasterArt);
        $refreshed = $actor->jobArtV2TimedEffect('canonical_self_buff:11:1');
        $this->assertNotSame($effect, $refreshed);
        $this->assertCount(1, $actor->jobArtV2TimedEffects());
        $this->assertSame(4, $refreshed?->remainingRounds);
        $service->endRound($state);
        $this->assertSame(4, $refreshed?->remainingRounds);

        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(3, $refreshed?->remainingRounds);
        $this->assertSame(115, $actor->effectiveStr());

        $state->turnCount = 4;
        $service->endRound($state);
        $this->assertSame(2, $refreshed?->remainingRounds);
        $state->turnCount = 5;
        $service->endRound($state);
        $state->turnCount = 6;
        $service->endRound($state);
        $this->assertNull($actor->jobArtV2TimedEffect('canonical_self_buff:11:1'));
        $this->assertSame(100, $actor->effectiveStr());
    }

    public function test_sanctuary_barrier_uses_a_two_turn_battle_memory_buff_instead_of_a_battle_long_legacy_buff(): void
    {
        [$actor, $target, $state] = $this->battle(66);
        $service = $this->service();
        $art = $this->art(56, 5, '聖域結界', 'MAGICAL_DAMAGE_BUFF', 255, 1, [
            'self_buff_percent' => 20,
            'duration_turns' => 2,
        ]);

        $state->turnCount = 1;
        $execution = $this->cast($service, $actor, $target, $state, $art);

        $effect = $actor->jobArtV2TimedEffect('canonical_self_buff:56:5');
        $this->assertNotNull($effect);
        $this->assertSame('MAGICAL_DAMAGE', (string) $execution->effect_template);
        $this->assertSame(0, (int) $execution->self_buff_percent);
        $this->assertSame(100, $actor->mag, 'The battle-only effect must not mutate the raw stat.');
        $this->assertSame(80, $actor->spr, 'The battle-only effect must not mutate the raw stat.');
        $this->assertSame(125, $actor->effectiveMag());
        $this->assertSame(96, $actor->effectiveSpr());
        $this->assertSame(4, $effect->remainingRounds);

        $service->endRound($state);
        $this->assertSame(4, $effect->remainingRounds, 'The cast turn must not shorten the four-turn effect.');

        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(3, $effect->remainingRounds);
        $this->assertSame(125, $actor->effectiveMag());
        $this->assertSame(96, $actor->effectiveSpr());

        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(2, $effect->remainingRounds);
        $state->turnCount = 4;
        $service->endRound($state);
        $state->turnCount = 5;
        $service->endRound($state);
        $this->assertNull($actor->jobArtV2TimedEffect('canonical_self_buff:56:5'));
        $this->assertSame(100, $actor->effectiveMag());
        $this->assertSame(80, $actor->effectiveSpr());
    }

    public function test_structured_debuffs_use_master_duration_without_mutating_raw_target_stats(): void
    {
        foreach ([
            [
                'job_id' => 33,
                'rank' => 9,
                'name' => '武神降臨',
                'duration' => 4,
                'attributes' => ['enemy_def_down_percent' => 20, 'enemy_spr_down_percent' => 20],
                'effective' => ['str' => 100, 'def' => 64, 'spr' => 64, 'mag' => 100, 'agi' => 100],
            ],
            [
                'job_id' => 36,
                'rank' => 5,
                'name' => '神罰の槌',
                'duration' => 3,
                'attributes' => ['enemy_mag_down_percent' => 18],
                'effective' => ['str' => 100, 'def' => 80, 'spr' => 80, 'mag' => 82, 'agi' => 100],
            ],
            [
                'job_id' => 12,
                'actor_job_id' => 62,
                'rank' => 9,
                'name' => '十面埋伏',
                'duration' => 4,
                'attributes' => [
                    'enemy_atk_down_percent' => 15,
                    'enemy_mag_down_percent' => 15,
                    'enemy_spd_down_percent' => 15,
                ],
                'effective' => ['str' => 85, 'def' => 80, 'spr' => 80, 'mag' => 85, 'agi' => 85],
            ],
        ] as $case) {
            $actor = $this->actor('actor', true, $case['actor_job_id'] ?? $case['job_id']);
            $target = $this->actor('target', false, 999, [
                'str' => 100,
                'def' => 80,
                'spr' => 80,
                'mag' => 100,
                'agi' => 100,
            ]);
            $state = new BattleState($actor, $target, 'pve');
            $state->turnCount = 1;
            $service = $this->service();
            $art = $this->art(
                $case['job_id'],
                $case['rank'],
                $case['name'],
                'DAMAGE_DEBUFF',
                255,
                1,
                ['duration_turns' => $case['duration'], ...$case['attributes']],
            );

            $this->beginAction($service, $actor, $state);
            $result = $service->applyTimedStructuredDebuffs($actor, $target, $state, $art);

            $this->assertNotNull($result, $case['name']);
            $this->assertSame($case['duration'], $result['duration_turns'], $case['name']);
            $this->assertNotEmpty($result['changes'], $case['name']);
            $this->assertSame(100, $target->str, $case['name'].' raw ATK');
            $this->assertSame(80, $target->def, $case['name'].' raw DEF');
            $this->assertSame(80, $target->spr, $case['name'].' raw SPR');
            $this->assertSame(100, $target->mag, $case['name'].' raw MAG');
            $this->assertSame(100, $target->agi, $case['name'].' raw SPD');
            $this->assertSame($case['effective']['str'], $target->effectiveStr(), $case['name'].' effective ATK');
            $this->assertSame($case['effective']['def'], $target->effectiveDef(), $case['name'].' effective DEF');
            $this->assertSame($case['effective']['spr'], $target->effectiveSpr(), $case['name'].' effective SPR');
            $this->assertSame($case['effective']['mag'], $target->effectiveMag(), $case['name'].' effective MAG');
            $this->assertSame($case['effective']['agi'], $target->effectiveAgi(), $case['name'].' effective SPD');

            $service->endRound($state);
            $this->assertCount(1, $target->jobArtV2TimedEffects(), $case['name'].' cast turn');
            for ($turn = 2; $turn <= $case['duration']; $turn++) {
                $state->turnCount = $turn;
                $service->endRound($state);
                $this->assertCount(1, $target->jobArtV2TimedEffects(), $case['name']." turn {$turn}");
            }

            $state->turnCount = $case['duration'] + 1;
            $service->endRound($state);
            $this->assertCount(0, $target->jobArtV2TimedEffects(), $case['name'].' expired');
            $this->assertSame(100, $target->effectiveStr(), $case['name'].' restored ATK');
            $this->assertSame(80, $target->effectiveDef(), $case['name'].' restored DEF');
            $this->assertSame(80, $target->effectiveSpr(), $case['name'].' restored SPR');
            $this->assertSame(100, $target->effectiveMag(), $case['name'].' restored MAG');
            $this->assertSame(100, $target->effectiveAgi(), $case['name'].' restored SPD');
            $this->assertStringContainsString('弱体効果が切れた', implode("\n", $state->logs));
        }
    }

    public function test_structured_debuffs_keep_legacy_processing_when_v2_resources_are_off(): void
    {
        [$actor, $target, $state] = $this->battle(33);
        $state->beginSourceAction();
        $art = $this->art(33, 9, '武神降臨', 'DAMAGE_DEBUFF', 255, 1, [
            'duration_turns' => 3,
            'enemy_def_down_percent' => 20,
            'enemy_spr_down_percent' => 10,
        ]);

        config(['battle.job_art_v2.resources' => false]);

        $this->assertNull($this->service()->applyTimedStructuredDebuffs($actor, $target, $state, $art));
        $this->assertCount(0, $target->jobArtV2TimedEffects());
        $this->assertSame(80, $target->effectiveDef());
        $this->assertSame(80, $target->effectiveSpr());
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

    public function test_mechanical_deployment_prepares_one_aim_rank_five_or_nine_within_four_own_actions(): void
    {
        [$actor, $target, $state] = $this->battle(65);
        $service = $this->service();
        $setup = $this->art(35, 1, '機巧展開', 'SELF_BUFF', 110, 0);
        $otherLineage = $this->art(61, 5, '別系譜の連携', 'MAGICAL_DAMAGE', 185, 1);
        $cannon = $this->art(35, 5, '魔導砲', 'MAGICAL_DAMAGE', 185, 1);

        $execution = $this->cast($service, $actor, $target, $state, $setup);
        $this->assertSame('SELF_BUFF', $execution->effect_template);
        $this->assertSame(110, (int) $execution->power);

        $prepared = $actor->jobArtV2PreparedEffect('aim_cannon_preparation');
        $this->assertNotNull($prepared);
        $this->assertSame(1, $prepared->charges);
        $this->assertSame(4, $prepared->remainingActionOpportunities);
        $this->assertSame(1.10, $prepared->multiplier);

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $otherLineage);
        $this->assertSame(3, $prepared->remainingActionOpportunities);
        $this->assertSame(1, $prepared->charges);
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $otherLineage, 1_000));

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $cannon);
        $this->assertNull($actor->jobArtV2PreparedEffect('aim_cannon_preparation'));
        $this->assertSame(1_100, $service->modifyJobArtDamage($actor, $state, $cannon, 1_000));
        $service->completeJobArtCast($actor, $target, $state, $cannon, HitResult::MISS);
    }

    public function test_mechanical_deployment_expires_after_four_non_trigger_own_actions(): void
    {
        [$actor, $target, $state] = $this->battle(65);
        $service = $this->service();
        $setup = $this->art(35, 1, '機巧展開', 'SELF_BUFF', 110, 0);

        $this->cast($service, $actor, $target, $state, $setup);
        for ($action = 1; $action <= 4; $action++) {
            $this->beginAction($service, $actor, $state);
            $service->markNonJobArtAction($actor, $state);
            $expected = 4 - $action;
            if ($expected > 0) {
                $this->assertSame(
                    $expected,
                    $actor->jobArtV2PreparedEffect('aim_cannon_preparation')?->remainingActionOpportunities,
                );
            }
        }

        $this->assertNull($actor->jobArtV2PreparedEffect('aim_cannon_preparation'));
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
        $guard = $actor->jobArtV2TimedEffect('canonical_self_buff:50:9');
        $this->assertNotNull($guard);
        $this->assertSame(['def' => 0.20, 'spr' => 0.20], $guard->statModifiers);
        $this->assertSame(5, $guard->remainingRounds);
    }

    public function test_pierce_preparations_wait_for_the_next_pierce_rank_five_or_nine(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $service = $this->service();
        $burst = $this->art(2, 1, '挑発撃', 'DAMAGE_BUFF', 90, 1);
        $flexible = $this->art(16, 1, '実戦勘', 'DAMAGE_BUFF', 100, 1);

        $this->cast($service, $actor, $target, $state, $burst);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));
        $service->markNonJobArtAction($actor);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));

        $wrongLineage = $this->art(11, 5, 'counter-rank-five', 'PHYSICAL_DAMAGE', 165, 1);
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $wrongLineage);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));

        $pierceRankFive = $this->art(2, 5, 'pierce-rank-five', 'PHYSICAL_DAMAGE', 145, 1);
        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $pierceRankFive);
        $this->assertNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));
        $this->assertSame(1_150, $service->modifyJobArtDamage($actor, $state, $pierceRankFive, 1_000));

        $this->cast($service, $actor, $target, $state, $flexible);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));
        $service->markNonJobArtAction($actor);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));

        $this->beginAction($service, $actor, $state);
        $service->beginJobArtCast($actor, $state, $wrongLineage);
        $this->assertNotNull($actor->jobArtV2PreparedEffect('pierce_flexible_prep'));

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

    public function test_dawn_break_reuses_the_adaptive_route_for_inherited_arts_in_all_battle_types(): void
    {
        foreach ([60, 62] as $currentJobId) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
                [$physicalActor, $physicalTarget, $physicalState] = $this->battle($currentJobId, $battleType, [
                    'str' => 5_000,
                    'mag' => 1,
                ]);
                $physicalTarget->def = 1;
                $physicalTarget->spr = 5_000;
                $service = $this->service();
                $art = $this->art(70, 5, '暁光ブレイク', 'HYBRID_DAMAGE', 315, 1, [
                    'damage_type' => 'hybrid',
                    'hybrid_scaling' => 'average',
                ]);
                $physicalExecution = clone $art;

                $this->beginAction($service, $physicalActor, $physicalState);
                mt_srand(70_500);
                $expectedRandom = mt_rand();
                mt_srand(70_500);
                $service->applyForExecution(
                    $physicalActor,
                    $physicalTarget,
                    $physicalState,
                    $art,
                    $physicalExecution,
                );

                $this->assertSame($expectedRandom, mt_rand(), "rng:{$currentJobId}:{$battleType}");
                $this->assertSame('PHYSICAL_DAMAGE', $physicalExecution->effect_template, "physical:{$currentJobId}:{$battleType}");
                $this->assertSame('physical', $physicalExecution->damage_type, "physical type:{$currentJobId}:{$battleType}");
                $this->assertSame('physical', $physicalExecution->getAttribute('job_art_v2_adaptive_route'));
                $this->assertSame(315, (int) $physicalExecution->power);
                $this->assertSame(1, (int) $physicalExecution->hit_count);

                [$magicalActor, $magicalTarget, $magicalState] = $this->battle($currentJobId, $battleType, [
                    'str' => 1,
                    'mag' => 5_000,
                ]);
                $magicalTarget->def = 5_000;
                $magicalTarget->spr = 1;
                $magicalExecution = clone $art;

                $this->beginAction($service, $magicalActor, $magicalState);
                $service->applyForExecution(
                    $magicalActor,
                    $magicalTarget,
                    $magicalState,
                    $art,
                    $magicalExecution,
                );

                $this->assertSame('MAGICAL_DAMAGE', $magicalExecution->effect_template, "magical:{$currentJobId}:{$battleType}");
                $this->assertSame('magical', $magicalExecution->damage_type, "magical type:{$currentJobId}:{$battleType}");
                $this->assertSame('magical', $magicalExecution->getAttribute('job_art_v2_adaptive_route'));
                $this->assertSame(315, (int) $magicalExecution->power);
                $this->assertSame(1, (int) $magicalExecution->hit_count);
            }
        }
    }

    public function test_dawn_break_keeps_the_legacy_hybrid_route_when_role_effects_are_disabled(): void
    {
        config(['battle.job_art_v2.resources' => false]);
        [$actor, $target, $state] = $this->battle(62);
        $service = $this->service();
        $art = $this->art(70, 5, '暁光ブレイク', 'HYBRID_DAMAGE', 315, 1, [
            'damage_type' => 'hybrid',
            'hybrid_scaling' => 'average',
        ]);
        $execution = clone $art;

        $this->beginAction($service, $actor, $state);
        mt_srand(70_501);
        $expectedRandom = mt_rand();
        mt_srand(70_501);
        $service->applyForExecution($actor, $target, $state, $art, $execution);

        $this->assertSame($expectedRandom, mt_rand());
        $this->assertSame('HYBRID_DAMAGE', $execution->effect_template);
        $this->assertSame('hybrid', $execution->damage_type);
        $this->assertNull($execution->getAttribute('job_art_v2_adaptive_route'));
        $this->assertSame(315, (int) $execution->power);
        $this->assertSame(1, (int) $execution->hit_count);
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
        $actor->jobArts = [$art];

        $this->cast($service, $actor, $target, $state, $art);

        $this->assertSame(1, $actor->hp);
        $this->assertSame(1, $actor->totalDamageTaken);
        $this->assertSame(2, $actor->getResource('eclipse'));
        $this->assertSame(130, $actor->effectiveStr());
        $this->assertSame(125, $actor->effectiveMag());

        $logs = implode("\n", $state->logs);
        $costPosition = strpos($logs, 'HPを代償にした');
        $resourcePosition = strpos($logs, '冥蝕 +2');
        $this->assertNotFalse($costPosition);
        $this->assertNotFalse($resourcePosition);
        $this->assertLessThan($resourcePosition, $costPosition);
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
        // effective SPR 125 * canonical 65% = floor(81), capped at max HP.
        $this->assertSame(181, $guard->hp);
        $this->assertSame(0, (int) $guardState->jobArtV2RoleAction()['execution_power'] - 65);
        $this->assertSame(0.25, $guard->jobArtV2GuardState()?->rate);
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

    public function test_holy_medicine_refunds_the_exact_conversion_hp_cost_even_in_a_healing_field(): void
    {
        [$actor, $target, $state] = $this->battle(67, actorOverrides: [
            'hp' => 449,
            'max_hp' => 1_001,
        ]);
        $service = $this->service();
        $medicine = $this->art(47, 1, '聖薬散布', 'REWARD_MIXED', 175, 0);
        $sourceActionId = $this->beginAction($service, $actor, $state);
        app(\App\Services\JobArtV2FieldService::class)
            ->deployPrimary($actor, $state, 'sanctuary', 80_001, $sourceActionId);
        $state->recordConversionResult(new ConversionResult(
            sourceActionId: $sourceActionId,
            actorKey: $state->actorKey($actor),
            hpBefore: 500,
            requestedHpCost: 51,
            actualHpLoss: 51,
            hpAfter: 449,
            spBeforeConversion: 300,
            requestedSpGain: 25,
            actualSpGain: 25,
            spAfterConversion: 325,
            success: true,
        ));

        $service->completeJobArtCast($actor, $target, $state, $medicine, null);

        $this->assertSame(500, $actor->hp, 'The HP exchange must be exactly ±0.');
        $this->assertStringContainsString('HPが 51 回復した', implode("\n", $state->logs));
    }

    public function test_shield_finishers_replace_permanent_buffs_with_one_charge_guards(): void
    {
        foreach ([
            'physical shield' => [44, '天壁イージス', 'DAMAGE_BUFF', 'PHYSICAL_DAMAGE', 0.35],
            'magical shield' => [56, '聖壁アルカディア', 'MAGICAL_DAMAGE_BUFF', 'MAGICAL_DAMAGE', 0.40],
        ] as $case => [$jobId, $name, $masterTemplate, $expectedTemplate, $expectedRate]) {
            [$actor, $target, $state] = $this->battle(62);
            $actor->normalAttackType = 'physical';
            $source = $this->art($jobId, 9, $name, $masterTemplate, 320, 1);
            $execution = $this->cast($this->service(), $actor, $target, $state, $source);

            $this->assertSame($expectedTemplate, $execution->effect_template, $case);
            $this->assertSame($expectedRate, $actor->jobArtV2GuardState()?->rate, $case);
            $this->assertSame(1, $actor->jobArtV2GuardState()?->charges, $case);
            $this->assertSame(100, $actor->str, $case);
            $this->assertSame(80, $actor->def, $case);
            $this->assertSame(100, $actor->mag, $case);
            $this->assertSame(80, $actor->spr, $case);
            $this->assertSame($masterTemplate, $source->effect_template, "{$case}: source master is immutable");
        }
    }

    public function test_magic_bow_stars_uses_mag_against_twenty_five_percent_ignored_defense(): void
    {
        [$actor, $target, $state] = $this->battle(62, actorOverrides: ['mag' => 180, 'str' => 999]);
        $target->def = 200;
        $target->baseDef = 200;
        $target->spr = 900;
        $target->baseSpr = 900;
        $service = $this->service();
        $source = $this->art(45, 5, '魔弓連星', 'MAGICAL_DAMAGE', 220, 1);
        $execution = $this->cast($service, $actor, $target, $state, $source);

        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame(25, $execution->getAttribute('job_art_v2_defense_ignore_percent'));
        $this->assertSame(
            ['attack' => 180, 'def' => 150, 'spr' => 150],
            $service->damageStatOverrides($actor, $target, $execution),
        );
        $this->assertSame(200, $target->def, 'The target master/runtime stat must not be mutated.');
        $this->assertSame(900, $target->spr);
    }

    public function test_poem_blessing_uses_the_canonical_four_turn_buff_without_mutating_raw_stats(): void
    {
        [$actor, $target, $state] = $this->battle(46, actorOverrides: ['mag' => 100, 'spr' => 100]);
        $service = $this->service();
        $source = $this->art(46, 1, '祝詞の一節', 'MAGICAL_DAMAGE_BUFF', 175, 1, [
            'duration_turns' => 2,
        ]);
        $execution = $this->cast($service, $actor, $target, $state, $source);

        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame(100, $actor->mag);
        $this->assertSame(100, $actor->spr);
        $this->assertSame(115, $actor->effectiveMag());
        $this->assertSame(110, $actor->effectiveSpr());
        $effect = $actor->jobArtV2TimedEffect('canonical_self_buff:46:1');
        $this->assertNotNull($effect);
        $this->assertSame(4, $effect->remainingRounds);

        $service->endRound($state);
        $state->turnCount = 1;
        $service->endRound($state);
        $this->assertSame(3, $effect->remainingRounds);
        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(2, $effect->remainingRounds);
        $state->turnCount = 3;
        $service->endRound($state);
        $state->turnCount = 4;
        $service->endRound($state);
        $this->assertNull($actor->jobArtV2TimedEffect('canonical_self_buff:46:1'));
        $this->assertSame(100, $actor->effectiveMag());
        $this->assertSame(100, $actor->effectiveSpr());
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
            'hp lower' => [['hp' => 20, 'max_hp' => 100, 'mp' => 80, 'max_mp' => 100], 'hp', 165, 10],
            'sp lower' => [['hp' => 80, 'max_hp' => 100, 'mp' => 20, 'max_mp' => 100], 'sp', 110, 15],
            'tie prefers hp' => [['hp' => 50, 'max_hp' => 100, 'mp' => 50, 'max_mp' => 100], 'hp', 165, 10],
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

    public function test_competitive_battles_suppress_role_reward_bonuses_and_reward_logs(): void
    {
        foreach (['pvp', 'champ', 'arena_npc'] as $battleType) {
            [$actor, $target, $state] = $this->battle(67, $battleType);
            $service = $this->service();
            $gold = $this->art(8, 5, '幸運の一手', 'REWARD_GOLD', 145, 0, [
                'gold_bonus_percent' => 7,
                'drop_bonus_percent' => 9,
                'rare_bonus_percent' => 4,
                'material_bonus_percent' => 3,
                'reward_scope' => 'normal_exploration_win_only',
            ]);
            $goldExecution = clone $gold;
            $this->beginAction($service, $actor, $state);
            $service->applyForExecution($actor, $target, $state, $gold, $goldExecution);

            $this->assertSame('REWARD_GOLD', $goldExecution->effect_template, $battleType);
            $this->assertSame('none', $goldExecution->reward_scope, $battleType);
            $this->assertSame(0, (int) $goldExecution->gold_bonus_percent, $battleType);
            $this->assertSame(0, (int) $goldExecution->drop_bonus_percent, $battleType);
            $this->assertSame(0, (int) $goldExecution->rare_bonus_percent, $battleType);
            $this->assertSame(0, (int) $goldExecution->material_bonus_percent, $battleType);

            $drop = $this->art(20, 5, '掘り出し物', 'REWARD_MIXED', 165, 0, [
                'gold_bonus_percent' => 12,
                'drop_bonus_percent' => 6,
                'rare_bonus_percent' => 3,
                'material_bonus_percent' => 2,
                'reward_scope' => 'normal_exploration_win_only',
            ]);
            $dropExecution = clone $drop;
            $this->beginAction($service, $actor, $state);
            $service->applyForExecution($actor, $target, $state, $drop, $dropExecution);

            $this->assertSame(0, $state->goldBonusPercent, $battleType);
            $this->assertSame(0, $state->dropBonusPercent, $battleType);
            $this->assertSame(0, $state->rareBonusPercent, $battleType);
            $this->assertSame(0, $state->materialBonusPercent, $battleType);
            $this->assertSame([], $state->logs, $battleType);
        }
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
        $power = $actor->jobArtV2TimedEffect('canonical_self_buff:38:9');
        $this->assertNotNull($power);
        $this->assertSame(['str' => 0.30, 'mag' => 0.30], $power->statModifiers);
        $this->assertSame(130, $actor->effectiveStr());
        $this->assertSame(130, $actor->effectiveMag());
        $this->assertSame(80, $actor->effectiveDef());
        $this->assertSame(80, $actor->effectiveSpr());
    }

    public function test_cathedral_heals_once_cleanses_all_and_grants_one_direct_damage_guard(): void
    {
        [$actor, $target, $state] = $this->battle(24, actorOverrides: [
            'hp' => 100,
            'max_hp' => 500,
            'spr' => 100,
        ]);
        $actor->conditions = [
            'burn' => ['rate' => 0.10, 'remaining_turns' => 2],
            'poison' => ['rate' => 0.10, 'remaining_turns' => 2],
            'slow' => ['rate' => 0.10, 'remaining_turns' => 2],
        ];
        $cathedral = $this->art(24, 9, '大聖堂の奇跡', 'HEAL_CLEANSE', 250, 0, [
            'damage_reduction_percent' => 20,
        ]);

        $this->cast($this->service(), $actor, $target, $state, $cathedral);

        $this->assertSame(350, $actor->hp, 'SPR 250% heal must be applied exactly once.');
        $this->assertSame([], $actor->conditions);
        $this->assertSame(['burn', 'poison', 'slow'], $state->cleanseResults()[0]->removedStates);
        $this->assertSame(0.30, $actor->jobArtV2GuardState()?->rate);
        $this->assertSame(1, $actor->jobArtV2GuardState()?->charges);
    }

    public function test_diamond_guard_and_giga_break_use_portable_shared_execution_semantics(): void
    {
        [$actor, $target, $state] = $this->battle(62);
        $service = $this->service();
        $diamond = $this->art(21, 9, '金剛不壊', 'GUARD_BARRIER', 89, 0, [
            'damage_reduction_percent' => 25,
        ]);
        $this->beginAction($service, $actor, $state);
        $diamondExecution = clone $diamond;
        $service->applyForExecution($actor, $target, $state, $diamond, $diamondExecution);
        $service->completeJobArtCast($actor, $target, $state, $diamond, null);

        $this->assertSame('V2_ROLE_EFFECT_ONLY', $diamondExecution->effect_template);
        $this->assertSame(0, (int) $diamondExecution->damage_reduction_percent);
        $this->assertSame(0.30, $actor->jobArtV2GuardState()?->rate);

        [$gigaActor, $gigaTarget, $gigaState] = $this->battle(62);
        $giga = $this->art(27, 9, 'ギガブレイク', 'MULTI_HIT', 315, 2, [
            'hybrid_scaling' => 'average',
        ]);
        $this->beginAction($service, $gigaActor, $gigaState);
        $gigaExecution = clone $giga;
        $service->applyForExecution($gigaActor, $gigaTarget, $gigaState, $giga, $gigaExecution);

        $this->assertSame('HYBRID_DAMAGE', $gigaExecution->effect_template);
        $this->assertSame('hybrid', $gigaExecution->damage_type);
        $this->assertSame(315, (int) $gigaExecution->power);
        $this->assertSame(2, (int) $gigaExecution->hit_count);
        $this->assertSame('average', (string) $gigaExecution->hybrid_scaling);
    }

    public function test_shared_v2_damage_buff_uses_normal_attack_type_and_canonical_timed_values(): void
    {
        [$physical, , $physicalState] = $this->battle(62, actorOverrides: ['str' => 100, 'def' => 80, 'mag' => 140, 'spr' => 90]);
        $physical->normalAttackType = 'physical';
        $art = $this->art(17, 9, '瞬影乱舞', 'DAMAGE_BUFF', 255, 4);

        $physicalState->beginSourceAction();
        $physicalChange = $this->service()->applySharedSelfBuff($physical, $physicalState, $art);
        $this->assertSame(['main_label' => 'ATK', 'main_before' => 100, 'main_after' => 135, 'sub_label' => 'DEF', 'sub_before' => 80, 'sub_after' => 96], $physicalChange);
        $this->assertSame(100, $physical->str);
        $this->assertSame(80, $physical->def);
        $this->assertSame(135, $physical->effectiveStr());
        $this->assertSame(96, $physical->effectiveDef());
        $this->assertSame(140, $physical->mag);
        $this->assertSame(90, $physical->spr);

        [$magical, , $magicalState] = $this->battle(62, actorOverrides: ['str' => 140, 'def' => 90, 'mag' => 100, 'spr' => 80]);
        $magical->normalAttackType = 'magical';
        $magicalState->beginSourceAction();
        $magicalChange = $this->service()->applySharedSelfBuff($magical, $magicalState, $art);
        $this->assertSame(['main_label' => 'MAG', 'main_before' => 100, 'main_after' => 135, 'sub_label' => 'SPR', 'sub_before' => 80, 'sub_after' => 96], $magicalChange);
        $this->assertSame(100, $magical->mag);
        $this->assertSame(80, $magical->spr);
        $this->assertSame(135, $magical->effectiveMag());
        $this->assertSame(96, $magical->effectiveSpr());
        $this->assertSame(140, $magical->str);
        $this->assertSame(90, $magical->def);

        config(['battle.job_art_v2.resources' => false]);
        [$legacy, , $legacyState] = $this->battle(62);
        $this->assertNull($this->service()->applySharedSelfBuff($legacy, $legacyState, $art));
        $this->assertSame(100, $legacy->str);
    }

    public function test_shared_v2_damage_buff_keeps_power_tier_fallback_without_a_canonical_entry(): void
    {
        [$actor, , $state] = $this->battle(62, actorOverrides: ['str' => 100, 'def' => 80]);
        $actor->normalAttackType = 'physical';
        $art = $this->art(999, 1, '正本未登録テスト', 'DAMAGE_BUFF', 255, 1);

        $state->beginSourceAction();
        $change = $this->service()->applySharedSelfBuff($actor, $state, $art);

        $this->assertSame(
            ['main_label' => 'ATK', 'main_before' => 100, 'main_after' => 120, 'sub_label' => 'DEF', 'sub_before' => 80, 'sub_after' => 88],
            $change,
        );
        $this->assertSame(120, $actor->str);
        $this->assertSame(88, $actor->def);
        $this->assertCount(0, $actor->jobArtV2TimedEffects());
    }

    public function test_golden_appraisal_marks_the_target_in_battle_memory(): void
    {
        [$actor, $target, $state] = $this->battle(67);
        $art = $this->art(31, 1, '黄金鑑定', 'REWARD_MIXED', 110, 0);

        $this->assertFalse($target->isJobArtV2Appraised());
        $this->cast($this->service(), $actor, $target, $state, $art);
        $this->assertTrue($target->isJobArtV2Appraised());
    }

    public function test_exorcising_strike_uses_attack_against_spirit_and_gains_exact_matchup_bonus(): void
    {
        [$actor, $target, $state] = $this->battle(62, actorOverrides: ['str' => 180, 'mag' => 999]);
        $service = $this->service();
        $art = $this->art(21, 5, '破邪拳', 'MAGICAL_DAMAGE', 165, 1);
        $actor->jobArtOrigins[(int) $art->id] = 'inherited';
        $execution = $this->cast($service, $actor, $target, $state, $art);

        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame(
            ['attack' => 180, 'def' => 80, 'spr' => 80],
            $service->damageStatOverrides($actor, $target, $execution),
        );
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $execution, 1_000));

        foreach ([
            ['species_keys' => ['mage']],
            ['species_keys' => ['undead']],
            ['normal_attack_type' => 'magical'],
        ] as $targetOverrides) {
            [$bonusActor, $bonusTarget, $bonusState] = $this->battle(
                62,
                actorOverrides: ['str' => 180, 'mag' => 999],
            );
            $bonusActor->jobArtOrigins[(int) $art->id] = 'inherited';
            $bonusTarget->speciesKeys = $targetOverrides['species_keys'] ?? [];
            $bonusTarget->normalAttackType = $targetOverrides['normal_attack_type'] ?? 'physical';
            $bonusExecution = $this->cast($service, $bonusActor, $bonusTarget, $bonusState, $art);

            $this->assertSame(
                1_200,
                $service->modifyJobArtDamage($bonusActor, $bonusState, $bonusExecution, 1_000),
            );
        }
    }

    public function test_alchemy_bomb_follows_normal_attack_type_and_applies_three_turn_mixed_debuff(): void
    {
        [$actor, $target, $state] = $this->battle(62);
        $service = $this->service();
        $actor->normalAttackType = 'physical';
        $art = $this->art(26, 5, '錬成爆弾', 'DAMAGE_DEBUFF', 165, 1, [
            'enemy_def_down_percent' => 99,
            'enemy_spr_down_percent' => 0,
            'duration_turns' => 9,
        ]);
        $actor->jobArtOrigins[(int) $art->id] = 'inherited';
        $execution = $this->cast($service, $actor, $target, $state, $art);

        $this->assertSame('PHYSICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('physical', $execution->damage_type);
        $this->assertSame(15, (int) $execution->enemy_def_down_percent);
        $this->assertSame(15, (int) $execution->enemy_spr_down_percent);
        $this->assertSame(3, (int) $execution->duration_turns);
        $applied = $service->applyTimedStructuredDebuffs($actor, $target, $state, $execution);
        $this->assertSame(3, $applied['duration_turns'] ?? null);
        $this->assertSame(68, $target->effectiveDef());
        $this->assertSame(68, $target->effectiveSpr());
        $this->assertSame(80, $target->def);
        $this->assertSame(80, $target->spr);

        $state->turnCount = 1;
        $service->endRound($state);
        $this->assertSame(68, $target->effectiveDef());
        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(68, $target->effectiveDef());
        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(80, $target->effectiveDef());
        $this->assertSame(80, $target->effectiveSpr());

        [$magicActor, $magicTarget, $magicState] = $this->battle(62);
        $magicActor->normalAttackType = 'magical';
        $magicActor->jobArtOrigins[(int) $art->id] = 'inherited';
        $magicExecution = $this->cast($service, $magicActor, $magicTarget, $magicState, $art);
        $this->assertSame('MAGICAL_DAMAGE', $magicExecution->effect_template);
        $this->assertSame('magical', $magicExecution->damage_type);
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

    public function test_sage_barrier_is_low_power_magic_and_reduces_damage_until_the_next_own_action(): void
    {
        $this->enableV2();
        [$actor, $target, $state] = $this->battle(29);
        $service = $this->service();
        $barrier = $this->art(
            29,
            5,
            '賢者の結界',
            'GUARD_BARRIER',
            185,
            1,
            ['damage_reduction_percent' => 18],
        );
        $actor->jobArtOrigins[(int) $barrier->id] = 'current';
        $actor->jobArtRates[(int) $barrier->id] = 1.0;

        $execution = $this->cast($service, $actor, $target, $state, $barrier, HitResult::MISS);

        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame(110, (int) $execution->power);
        $this->assertSame(1.1, (float) $execution->power_multiplier);
        $this->assertSame(0, (int) $execution->damage_reduction_percent);
        $this->assertSame(18, $actor->damageReductionRate);
        $this->assertStringContainsString('次の自分の行動開始まで', implode('\n', $state->logs));

        $this->beginAction($service, $actor, $state);
        $this->assertSame(0, $actor->damageReductionRate);

        [$inheritedActor, $inheritedTarget, $inheritedState] = $this->battle(62);
        $inheritedActor->jobArtOrigins[(int) $barrier->id] = 'inherited';
        $inheritedActor->jobArtRates[(int) $barrier->id] = 0.8;
        $inheritedExecution = $this->cast(
            $service,
            $inheritedActor,
            $inheritedTarget,
            $inheritedState,
            $barrier,
        );

        $this->assertSame(110, (int) $inheritedExecution->power);
        $this->assertSame(18, $inheritedActor->damageReductionRate);
        $this->assertSame(185, (int) $barrier->power);
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
