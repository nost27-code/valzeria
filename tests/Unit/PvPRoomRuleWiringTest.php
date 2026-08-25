<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\Battle\NullPvPRoomRule;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\MiraclePvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\PvPBattleService;
use App\Services\ResourceChangeResult;
use Mockery;
use Tests\TestCase;

final class PvPRoomRuleWiringTest extends TestCase
{
    public function test_unregistered_states_fall_back_to_one_shared_null_rule_and_rules_are_state_scoped(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $service = $this->harness($support);
        [$attacker, $defender] = $this->actors();
        $firstState = new BattleState($attacker, $defender, 'pvp');
        $secondState = new BattleState($attacker, $defender, 'pvp');

        $this->assertInstanceOf(NullPvPRoomRule::class, $service->ruleForTest($firstState));
        $this->assertSame($service->ruleForTest($firstState), $service->ruleForTest($secondState));

        $firstRule = new SpyPvPRoomRule();
        $secondRule = new SpyPvPRoomRule();
        $service->bindRule($firstState, $firstRule);
        $service->bindRule($secondState, $secondRule);

        $this->assertSame($firstRule, $service->ruleForTest($firstState));
        $this->assertSame($secondRule, $service->ruleForTest($secondState));
        $this->assertNotSame($service->ruleForTest($firstState), $service->ruleForTest($secondState));
    }

    public function test_battle_start_and_every_initiative_reroll_use_the_rule_before_the_first_action(): void
    {
        $rule = new SpyPvPRoomRule();
        $rule->invertInitiative = true;
        $rule->killActorOnActionEnd = true;
        $support = new PvPRoomRuleJobArtSupportStub();
        $support->roleEffectsEnabled = true;
        $support->rerollInitiative = true;
        $service = $this->loopHarness($support, $rule, attackerAgi: 1_000, defenderAgi: 1);

        $resolution = $service->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(roomRule: $rule),
        );

        $this->assertSame(1, $rule->battleStartCalls);
        $this->assertCount(2, $rule->initiativeCalls, 'The initial comparison and the Job Art reroll must share the hook.');
        $this->assertTrue($rule->initiativeCalls[0]['default_attacker_first']);
        $this->assertTrue($rule->initiativeCalls[1]['default_attacker_first']);
        $this->assertSame([false], $support->adjustInitiativeInputs);
        $this->assertSame(['防衛者'], $service->actionActors);
        $this->assertSame([
            'battle_start',
            'initiative',
            'initiative',
            'execute:防衛者',
            'action_end:防衛者',
        ], $rule->events);
        $this->assertSame('victory', $resolution->result->result);
    }

    public function test_divine_speed_preserves_the_existing_low_agi_initiative_result(): void
    {
        $service = $this->harness();
        [$attacker, $defender] = $this->actors(attackerAgi: 80, defenderAgi: 100);
        $state = new BattleState($attacker, $defender, 'pvp');
        $service->bindRule($state, new DivineSpeedPvPRoomRule());

        $this->assertFalse($service->runBaseInitiative($attacker, $defender, $state, true));
    }

    public function test_reverse_time_controls_both_initial_and_job_art_reroll_initiative(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $support->roleEffectsEnabled = true;
        $support->rerollInitiative = true;
        $status = Mockery::mock(CharacterStatusService::class);
        $status->shouldReceive('getFinalStats')->twice()->andReturn(
            $this->stats(agi: 80),
            $this->stats(agi: 100),
        );
        $service = new PvPSpeedRoomRuleLoopHarness(
            $status,
            new PvPRoomRuleDamageCalculatorSpy(),
            $support,
            new DamageApplicationService(),
        );

        $resolution = $service->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(roomRule: new ReverseTimePvPRoomRule()),
        );

        $this->assertSame([true], $support->adjustInitiativeInputs);
        $this->assertSame(['挑戦者'], $service->actionActors);
        $this->assertSame('victory', $resolution->result->result);
    }

    public function test_action_end_runs_once_after_the_action_and_before_the_death_check(): void
    {
        $rule = new SpyPvPRoomRule();
        $rule->killActorOnActionEnd = true;
        $support = new PvPRoomRuleJobArtSupportStub();
        $service = $this->loopHarness($support, $rule, attackerAgi: 1_000, defenderAgi: 1);

        $resolution = $service->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(roomRule: $rule),
        );

        $this->assertSame(['挑戦者'], $service->actionActors);
        $this->assertSame(['挑戦者'], array_column($rule->actionEndCalls, 'actor'));
        $this->assertSame([
            'battle_start',
            'initiative',
            'execute:挑戦者',
            'action_end:挑戦者',
        ], $rule->events);
        $this->assertSame('defeat', $resolution->result->result);
    }

    public function test_damage_stat_hook_supplies_overrides_to_normal_attack_and_skill_calculations(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $support->existingDamageStatOverrides = ['attack' => 111, 'def' => 222, 'spr' => 333];
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $service = $this->harness($support, $calculator);
        [$attacker, $defender] = $this->actors(defenderHp: 10_000);
        $state = new BattleState($attacker, $defender, 'pvp');
        $state->rankBattleMinimumDamageGuaranteeEnabled = false;
        $state->rankBattleDamageCapEnabled = false;
        $state->rankBattleBaseDamageMultiplier = 0.5;
        $rule = new SpyPvPRoomRule();
        $rule->damageStatReplacements = ['attack' => 10, 'def' => 20, 'spr' => 30];
        $service->bindRule($state, $rule);

        $service->runNormalAttack($attacker, $defender, $state);
        $service->runPhysicalAttack($attacker, $defender, $state);
        $physicalSkill = $this->skill('active', 'PHYSICAL_DAMAGE', 'physical', 7_001);
        $magicalSkill = $this->skill('active', 'MAGICAL_DAMAGE', 'magical', 7_002);
        $hybridSkill = $this->skill('active', 'HYBRID_DAMAGE', 'hybrid', 7_003, [
            'hybrid_scaling' => 'average',
        ]);
        $service->runSkill($attacker, $defender, $state, $physicalSkill);
        $service->runSkill($attacker, $defender, $state, $magicalSkill);
        $service->runSkill($attacker, $defender, $state, $hybridSkill);

        $this->assertCount(5, $calculator->rankDamageCalls);
        foreach ($calculator->rankDamageCalls as $call) {
            $this->assertSame(10, $call['attack']);
            $this->assertSame(20, $call['def']);
            $this->assertSame(30, $call['spr']);
            $this->assertFalse($call['minimum_damage_guarantee_enabled']);
            $this->assertFalse($call['damage_cap_enabled']);
            $this->assertSame(0.5, $call['base_damage_multiplier']);
        }
        $this->assertSame(['attack' => null, 'def' => null, 'spr' => null], $rule->damageStatCalls[0]['incoming']);
        $this->assertSame(['attack' => null, 'def' => null, 'spr' => null], $rule->damageStatCalls[1]['incoming']);
        $this->assertSame(['attack' => 111, 'def' => 222, 'spr' => 333], $rule->damageStatCalls[2]['incoming']);
        $this->assertSame(['attack' => 111, 'def' => 222, 'spr' => 333], $rule->damageStatCalls[3]['incoming']);
        $this->assertSame(['attack' => 100, 'def' => 222, 'spr' => 333], $rule->damageStatCalls[4]['incoming']);
        $this->assertSame(['physical', 'physical', 'physical', 'magical', 'hybrid'], array_column($rule->damageStatCalls, 'attack_type'));
        $this->assertSame(DamageSourceType::NORMAL_ATTACK, $rule->damageStatCalls[0]['source_type']);
        $this->assertNull($rule->damageStatCalls[0]['skill']);
        $this->assertSame(DamageSourceType::NORMAL_ATTACK, $rule->damageStatCalls[1]['source_type']);
        $this->assertNull($rule->damageStatCalls[1]['skill']);
        $this->assertSame(DamageSourceType::JOB_SKILL, $rule->damageStatCalls[2]['source_type']);
        $this->assertSame($physicalSkill, $rule->damageStatCalls[2]['skill']);
        $this->assertSame($magicalSkill, $rule->damageStatCalls[3]['skill']);
        $this->assertSame($hybridSkill, $rule->damageStatCalls[4]['skill']);
    }

    public function test_speed_breakthrough_snapshot_and_log_are_reused_for_every_hit_in_one_action(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $calculator->mutateAttackerAgiAfterFirstCall = true;
        $service = $this->harness($support, $calculator);
        [$attacker, $defender] = $this->actors(
            defenderHp: 10_000,
            attackerAgi: 154,
            defenderAgi: 100,
        );
        $state = new BattleState($attacker, $defender, 'pvp');
        $state->speedBreakthroughEnabled = true;
        $state->beginCompetitiveAction($attacker, $defender);
        $state->snapshotSpeedBreakthrough($attacker, $defender, 0.30);
        $skill = $this->skill('active', 'MULTI_HIT', 'physical', 7_050, [
            'hit_count' => 3,
            'power' => 300,
            'power_multiplier' => 3.0,
        ]);

        $service->runSkill($attacker, $defender, $state, $skill);

        $this->assertCount(3, $calculator->rankDamageCalls);
        $this->assertSame(
            [0.30, 0.30, 0.30],
            array_column($calculator->rankDamageCalls, 'additional_defense_ignore_rate'),
        );
        $this->assertSame(1, $attacker->agi, 'The calculator spy changed agility after hit 1.');
        $this->assertCount(
            1,
            array_filter($state->logs, static fn (string $log): bool => str_contains($log, '【敏捷突破】')),
        );
    }

    public function test_speed_breakthrough_does_not_add_to_an_existing_fifty_percent_ignore(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $support->existingDamageStatOverrides = [
            'attack' => 100,
            'def' => 50,
            'spr' => 50,
            'applied_ignore_rate' => 0.50,
        ];
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $service = $this->harness($support, $calculator);
        [$attacker, $defender] = $this->actors(defenderHp: 10_000, attackerAgi: 154, defenderAgi: 100);
        $state = new BattleState($attacker, $defender, 'pvp');
        $state->speedBreakthroughEnabled = true;
        $state->beginCompetitiveAction($attacker, $defender);
        $state->snapshotSpeedBreakthrough($attacker, $defender, 0.30);

        $service->runSkill(
            $attacker,
            $defender,
            $state,
            $this->skill('job_art', 'PHYSICAL_DAMAGE', 'physical', 7_051),
            HitResult::HIT,
        );

        $this->assertSame(0.0, $calculator->rankDamageCalls[0]['additional_defense_ignore_rate']);
        $this->assertCount(
            0,
            array_filter($state->logs, static fn (string $log): bool => str_contains($log, '【敏捷突破】')),
        );
        $this->assertSame(0.50, $state->competitiveMetricsFor($attacker)['speed_rates'][0]['combined_ignore_rate']);
    }

    public function test_seal_rules_cover_all_five_damage_paths_without_treating_hybrid_as_str(): void
    {
        $cases = [
            'seal magic' => [new SealMagicPvPRoomRule(), [0, null, null, 0, 150]],
            'seal blade' => [new SealBladePvPRoomRule(), [null, 0, 0, null, 150]],
        ];

        foreach ($cases as $name => [$rule, $expectedAttackOverrides]) {
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness(calculator: $calculator);
            [$attacker, $defender] = $this->actors(
                defenderHp: 10_000,
                attackerStr: 100,
                attackerMag: 200,
                attackerNormalAttackType: 'adaptive',
            );
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, $rule);

            $service->runNormalAttack($attacker, $defender, $state);
            $service->runPhysicalAttack($attacker, $defender, $state);
            $service->runSkill($attacker, $defender, $state, $this->skill('active', 'PHYSICAL_DAMAGE', 'physical', 7_101));
            $service->runSkill($attacker, $defender, $state, $this->skill('active', 'MAGICAL_DAMAGE', 'magical', 7_102));
            $service->runSkill($attacker, $defender, $state, $this->skill('active', 'HYBRID_DAMAGE', 'hybrid', 7_103, [
                'hybrid_scaling' => 'average',
            ]));

            $this->assertSame(
                $expectedAttackOverrides,
                array_column($calculator->rankDamageCalls, 'attack'),
                $name,
            );
            $this->assertSame(
                ['magical', 'physical', 'physical', 'magical', 'physical'],
                array_column($calculator->rankDamageCalls, 'attack_type'),
                $name,
            );
            $this->assertSame(100, $attacker->str, $name);
            $this->assertSame(200, $attacker->mag, $name);
            $this->assertSame(100, $attacker->effectiveStr(), $name);
            $this->assertSame(200, $attacker->effectiveMag(), $name);
        }
    }

    public function test_adaptive_route_is_resolved_before_sealing_without_fallback(): void
    {
        $cases = [
            'MAG greater than STR is sealed by seal magic' => [new SealMagicPvPRoomRule(), 100, 200, 'magical', 0],
            'STR greater than MAG is not sealed by seal magic' => [new SealMagicPvPRoomRule(), 200, 100, 'physical', null],
            'equal stats stay physical under seal magic' => [new SealMagicPvPRoomRule(), 100, 100, 'physical', null],
            'equal stats stay physical and are sealed by seal blade' => [new SealBladePvPRoomRule(), 100, 100, 'physical', 0],
        ];

        foreach ($cases as $name => [$rule, $str, $mag, $expectedAttackType, $expectedOverride]) {
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness(calculator: $calculator);
            [$attacker, $defender] = $this->actors(
                attackerStr: $str,
                attackerMag: $mag,
                attackerNormalAttackType: 'adaptive',
            );
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, $rule);

            $service->runNormalAttack($attacker, $defender, $state);

            $this->assertSame($expectedAttackType, $calculator->rankDamageCalls[0]['attack_type'], $name);
            $this->assertSame($expectedOverride, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame($str, $attacker->str, $name);
            $this->assertSame($mag, $attacker->mag, $name);
        }
    }

    public function test_v2_explicit_attack_stat_precedes_the_damage_category_and_preserves_defenses(): void
    {
        $cases = [
            'physical category with MAG is blocked by seal magic' => [new SealMagicPvPRoomRule(), 'physical', 'mag', 0],
            'physical category with MAG is kept by seal blade' => [new SealBladePvPRoomRule(), 'physical', 'mag', 1_800],
            'magical category with STR is blocked by seal blade' => [new SealBladePvPRoomRule(), 'magical', 'str', 0],
            'magical category with STR is kept by seal magic' => [new SealMagicPvPRoomRule(), 'magical', 'str', 1_800],
        ];

        foreach ($cases as $name => [$rule, $damageType, $attackStat, $expectedAttack]) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->existingDamageStatOverrides = ['attack' => 1_800, 'def' => 300, 'spr' => 200];
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(defenderHp: 10_000);
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, $rule);
            $skill = $this->skill(
                'job_art',
                $damageType === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE',
                $damageType,
                7_200,
            );
            $skill->setAttribute('job_art_v2_attack_stat', $attackStat);

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertSame($expectedAttack, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame(300, $calculator->rankDamageCalls[0]['def'], $name);
            $this->assertSame(200, $calculator->rankDamageCalls[0]['spr'], $name);
        }
    }

    public function test_drain_follows_its_existing_resolved_physical_or_magical_route(): void
    {
        $cases = [
            'physical drain is blocked by seal blade' => [new SealBladePvPRoomRule(), 'physical', 0],
            'physical drain is kept by seal magic' => [new SealMagicPvPRoomRule(), 'physical', 700],
            'magical drain is blocked by seal magic' => [new SealMagicPvPRoomRule(), 'magical', 0],
            'magical drain is kept by seal blade' => [new SealBladePvPRoomRule(), 'magical', 700],
        ];

        foreach ($cases as $name => [$rule, $damageType, $expectedAttack]) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->existingDamageStatOverrides = ['attack' => 700, 'def' => 300, 'spr' => 200];
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(attackerHp: 500, defenderHp: 10_000);
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, $rule);

            $service->runSkill($attacker, $defender, $state, $this->skill('job_art', 'DRAIN', $damageType, 7_300, [
                'drain_hp_rate' => 0.5,
            ]), HitResult::HIT);

            $this->assertSame($expectedAttack, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame($damageType, $calculator->rankDamageCalls[0]['attack_type'], $name);
        }
    }

    public function test_zero_attack_override_still_uses_the_existing_rank_damage_floor(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $calculator->useRealCalculation = true;
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(
            defenderHp: 1_000,
            attackerStr: 500,
            attackerMag: 100,
        );
        $state = new BattleState($attacker, $defender, 'pvp');
        $service->bindRule($state, new SealBladePvPRoomRule());

        $service->runPhysicalAttack($attacker, $defender, $state);

        $this->assertSame(0, $calculator->rankDamageCalls[0]['attack']);
        $this->assertGreaterThan(0, 1_000 - $defender->hp);
    }

    public function test_miracle_replaces_attack_power_across_all_five_damage_paths_without_changing_categories(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(
            defenderHp: 10_000,
            attackerStr: 100,
            attackerMag: 200,
            attackerLuk: 100,
            attackerNormalAttackType: 'adaptive',
        );
        $state = new BattleState($attacker, $defender, 'pvp');
        $service->bindRule($state, new MiraclePvPRoomRule());

        $service->runNormalAttack($attacker, $defender, $state);
        $service->runPhysicalAttack($attacker, $defender, $state);
        $service->runSkill($attacker, $defender, $state, $this->skill('active', 'PHYSICAL_DAMAGE', 'physical', 7_401));
        $service->runSkill($attacker, $defender, $state, $this->skill('active', 'MAGICAL_DAMAGE', 'magical', 7_402));
        $service->runSkill($attacker, $defender, $state, $this->skill('active', 'HYBRID_DAMAGE', 'hybrid', 7_403, [
            'hybrid_scaling' => 'average',
        ]));

        $this->assertSame([125, 125, 125, 125, 125], array_column($calculator->rankDamageCalls, 'attack'));
        $this->assertSame(
            ['magical', 'physical', 'physical', 'magical', 'physical'],
            array_column($calculator->rankDamageCalls, 'attack_type'),
        );
        $this->assertSame([100, 200, 100], [$attacker->str, $attacker->mag, $attacker->luk]);
        $this->assertSame([100, 200, 100], [
            $attacker->effectiveStr(),
            $attacker->effectiveMag(),
            $attacker->effectiveLuk(),
        ]);
    }

    public function test_miracle_keeps_the_existing_adaptive_category_boundary_before_replacing_attack_power(): void
    {
        $cases = [
            'MAG greater than STR stays magical' => [100, 200, 'magical'],
            'MAG equal to STR stays physical' => [100, 100, 'physical'],
            'MAG lower than STR stays physical' => [200, 100, 'physical'],
        ];

        foreach ($cases as $name => [$str, $mag, $expectedAttackType]) {
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness(calculator: $calculator);
            [$attacker, $defender] = $this->actors(
                attackerStr: $str,
                attackerMag: $mag,
                attackerLuk: 100,
                attackerNormalAttackType: 'adaptive',
            );
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, new MiraclePvPRoomRule());

            $service->runNormalAttack($attacker, $defender, $state);

            $this->assertSame($expectedAttackType, $calculator->rankDamageCalls[0]['attack_type'], $name);
            $this->assertSame(125, $calculator->rankDamageCalls[0]['attack'], $name);
        }
    }

    public function test_miracle_overrides_every_v2_explicit_attack_stat_but_preserves_damage_category_and_defenses(): void
    {
        $cases = [
            'STR on magical category' => ['str', 'magical'],
            'MAG on physical category' => ['mag', 'physical'],
            'AGI' => ['agi', 'physical'],
            'DEF' => ['def', 'physical'],
            'SPR' => ['spr', 'magical'],
            'LUK' => ['luk', 'magical'],
        ];

        foreach ($cases as $name => [$attackStat, $damageType]) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->existingDamageStatOverrides = ['attack' => 1_800, 'def' => 600, 'spr' => 400];
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(defenderHp: 10_000, attackerLuk: 100);
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, new MiraclePvPRoomRule());
            $skill = $this->skill(
                'job_art',
                $damageType === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE',
                $damageType,
                7_500,
            );
            $skill->setAttribute('job_art_v2_attack_stat', $attackStat);

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertSame(125, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame($damageType, $calculator->rankDamageCalls[0]['attack_type'], $name);
            $this->assertSame(600, $calculator->rankDamageCalls[0]['def'], $name);
            $this->assertSame(400, $calculator->rankDamageCalls[0]['spr'], $name);
        }
    }

    public function test_miracle_replaces_physical_and_magical_drain_attack_power_without_changing_drain_routes(): void
    {
        foreach (['physical', 'magical'] as $damageType) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->existingDamageStatOverrides = ['attack' => 900, 'def' => 300, 'spr' => 200];
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(
                attackerHp: 500,
                attackerMaxHp: 1_000,
                defenderHp: 10_000,
                attackerLuk: 100,
            );
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, new MiraclePvPRoomRule());

            $service->runSkill($attacker, $defender, $state, $this->skill('job_art', 'DRAIN', $damageType, 7_600, [
                'drain_hp_rate' => 0.5,
            ]), HitResult::HIT);

            $this->assertSame(125, $calculator->rankDamageCalls[0]['attack'], $damageType);
            $this->assertSame($damageType, $calculator->rankDamageCalls[0]['attack_type'], $damageType);
            $this->assertSame(550, $attacker->hp, $damageType);
        }
    }

    public function test_luk_power_contribution_hook_runs_once_before_multi_hit_power_split(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(defenderHp: 10_000, attackerLuk: 100);
        $state = new BattleState($attacker, $defender, 'pvp');
        $rule = new SpyPvPRoomRule();
        $rule->lukPowerContributionResult = 0;
        $service->bindRule($state, $rule);
        $skill = $this->skill('job_art', 'MULTI_HIT', 'physical', 7_700, [
            'power_multiplier' => 2.0,
            'hit_count' => 2,
            'luk_power_rate' => 0.5,
        ]);

        $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

        $this->assertSame([50], array_column($rule->lukPowerContributionCalls, 'contribution'));
        $this->assertSame([100, 100], array_column($calculator->rankDamageCalls, 'skill_power'));
        $this->assertSame(0.5, $skill->luk_power_rate);
    }

    public function test_miracle_suppresses_luk_power_contribution_while_null_rule_preserves_it(): void
    {
        $cases = [
            'miracle' => [new MiraclePvPRoomRule(), 200, 125],
            'normal PvP null rule' => [new NullPvPRoomRule(), 250, null],
        ];

        foreach ($cases as $name => [$rule, $expectedSkillPower, $expectedAttack]) {
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness(calculator: $calculator);
            [$attacker, $defender] = $this->actors(defenderHp: 10_000, attackerLuk: 100);
            $state = new BattleState($attacker, $defender, 'pvp');
            $service->bindRule($state, $rule);
            $skill = $this->skill('job_art', 'PHYSICAL_DAMAGE', 'physical', 7_800, [
                'power_multiplier' => 2.0,
                'luk_power_rate' => 0.5,
            ]);

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertSame($expectedSkillPower, $calculator->rankDamageCalls[0]['skill_power'], $name);
            $this->assertSame($expectedAttack, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame(0.5, $skill->luk_power_rate, $name);
        }
    }

    public function test_miracle_attack_override_still_uses_the_existing_rank_damage_floor_and_cap_pipeline(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $calculator->useRealCalculation = true;
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(
            defenderHp: 1_000,
            attackerStr: 2_000,
            attackerMag: 100,
            attackerLuk: 100,
        );
        $state = new BattleState($attacker, $defender, 'pvp');
        $service->bindRule($state, new MiraclePvPRoomRule());

        $service->runPhysicalAttack($attacker, $defender, $state);

        $this->assertSame(125, $calculator->rankDamageCalls[0]['attack']);
        $this->assertGreaterThan(0, 1_000 - $defender->hp);
    }

    public function test_final_damage_runs_after_field_and_job_art_modifiers_and_immediately_before_application(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $support->fieldDamageResult = 90;
        $support->jobArtDamageResult = 80;
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $calculator->damage = 100;
        $service = $this->harness($support, $calculator);
        [$attacker, $defender] = $this->actors(defenderHp: 500);
        $state = new BattleState($attacker, $defender, 'pvp');
        $rule = new SpyPvPRoomRule();
        $rule->finalDamageResult = 40;
        $service->bindRule($state, $rule);

        $service->runSkill(
            $attacker,
            $defender,
            $state,
            $this->skill('job_art', 'MULTI_HIT', 'physical', 8_001),
            HitResult::HIT,
        );

        $this->assertSame([100], $support->fieldDamageInputs);
        $this->assertSame([90], $support->jobArtDamageInputs);
        $this->assertSame([80], array_column($rule->finalDamageCalls, 'damage'));
        $this->assertSame(460, $defender->hp);
        $this->assertStringContainsString('>40</span> のダメージ', implode("\n", $state->logs));
    }

    public function test_divine_speed_changes_actual_hp_loss_and_null_rule_keeps_the_baseline(): void
    {
        foreach ([false, true] as $damageApplicationEnabled) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->damageApplicationEnabled = $damageApplicationEnabled;
            $service = $this->harness($support);

            [$source, $target] = $this->actors(
                defenderHp: 500,
                defenderMaxHp: 500,
                attackerAgi: 150,
                defenderAgi: 100,
            );
            $state = new BattleState($source, $target, 'pvp');
            $service->bindRule($state, new DivineSpeedPvPRoomRule());
            $modified = $service->runResolvedDamage(
                $source,
                $target,
                $state,
                100,
                DamageSourceType::NORMAL_ATTACK,
            );

            $this->assertSame(120, $modified?->requestedDamage);
            $this->assertSame(120, $modified?->actualHpLoss);
            $this->assertSame(380, $target->hp);

            [$nullSource, $nullTarget] = $this->actors(
                defenderHp: 500,
                defenderMaxHp: 500,
                attackerAgi: 150,
                defenderAgi: 100,
            );
            $nullState = new BattleState($nullSource, $nullTarget, 'pvp');
            $baseline = $service->runResolvedDamage(
                $nullSource,
                $nullTarget,
                $nullState,
                100,
                DamageSourceType::NORMAL_ATTACK,
            );

            $this->assertSame(100, $baseline?->requestedDamage);
            $this->assertSame(100, $baseline?->actualHpLoss);
            $this->assertSame(400, $nullTarget->hp);
        }
    }

    public function test_reverse_time_does_not_change_hit_or_evasion_agi_inputs(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(attackerAgi: 80, defenderAgi: 100);
        $state = new BattleState($attacker, $defender, 'pvp');
        $service->bindRule($state, new ReverseTimePvPRoomRule());

        $service->runNormalAttack($attacker, $defender, $state);

        $this->assertSame([
            ['attacker' => 80, 'defender' => 100],
        ], $calculator->hitAgiCalls);
        $this->assertSame(80, $attacker->agi);
        $this->assertSame(100, $defender->agi);
    }

    public function test_actual_hp_loss_uses_hp_delta_with_damage_application_both_off_and_on(): void
    {
        foreach ([false, true] as $damageApplicationEnabled) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->damageApplicationEnabled = $damageApplicationEnabled;
            $service = $this->harness($support);
            [$attacker, $defender] = $this->actors(defenderHp: 30);
            $state = new BattleState($attacker, $defender, 'pvp');
            $rule = new SpyPvPRoomRule();
            $service->bindRule($state, $rule);

            $result = $service->runResolvedDamage(
                $attacker,
                $defender,
                $state,
                100,
                DamageSourceType::JOB_ART,
                9_001,
            );

            $this->assertSame(0, $defender->hp, $damageApplicationEnabled ? 'DamageApplication ON' : 'DamageApplication OFF');
            $this->assertSame(30, $result?->actualHpLoss);
            $this->assertSame([30], array_column($rule->actualHpLossCalls, 'actual_hp_loss'));
            $this->assertSame(DamageSourceType::JOB_ART, $rule->actualHpLossCalls[0]['source_type']);
            $this->assertSame(9_001, $rule->actualHpLossCalls[0]['source_id']);
        }
    }

    public function test_healing_hook_changes_real_hp_and_preserves_max_hp_clamping(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $service = $this->harness($support);
        [$source, $target] = $this->actors(attackerHp: 300, defenderHp: 100, defenderMaxHp: 300);
        $state = new BattleState($source, $target, 'pvp');
        $rule = new SpyPvPRoomRule();
        $rule->halveHealing = true;
        $service->bindRule($state, $rule);

        $this->assertSame(50, $service->runResolvedHealing($source, $target, $state, 100, 10_001));
        $this->assertSame(150, $target->hp);

        $target->hp = 280;
        $this->assertSame(20, $service->runResolvedHealing($source, $target, $state, 100, 10_002));
        $this->assertSame(300, $target->hp);
        $this->assertSame([100, 100], array_column($rule->healingCalls, 'amount'));
        $this->assertSame([10_001, 10_002], array_column($rule->healingCalls, 'source_id'));
        $this->assertSame(2, $support->completedHpHeals);
    }

    public function test_every_legacy_pvp_hp_recovery_route_uses_the_healing_hook(): void
    {
        $cases = [
            'heal_percent' => [$this->skill('active', 'SUPPORT', 'support', 11_001, [
                'power_multiplier' => 0,
                'hit_count' => 0,
                'heal_percent' => 10,
            ]), 100, 50],
            'HEAL' => [$this->skill('job_art', 'HEAL', 'heal', 11_002, [
                'power' => 100,
                'power_multiplier' => 0,
                'hit_count' => 0,
            ]), 100, 50],
            'HEAL_CLEANSE' => [$this->skill('job_art', 'HEAL_CLEANSE', 'heal', 11_003, [
                'power' => 100,
                'power_multiplier' => 0,
                'hit_count' => 0,
            ]), 100, 50],
            'DRAIN' => [$this->skill('job_art', 'DRAIN', 'physical', 11_004, [
                'drain_hp_rate' => 0.5,
            ]), 50, 25],
        ];

        foreach ($cases as $name => [$skill, $expectedRequestedHeal, $expectedActualHeal]) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $calculator->damage = 100;
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(attackerHp: 100, attackerMaxHp: 1_000, defenderHp: 1_000);
            $state = new BattleState($attacker, $defender, 'pvp');
            $rule = new SpyPvPRoomRule();
            $rule->halveHealing = true;
            $service->bindRule($state, $rule);

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertCount(1, $rule->healingCalls, $name);
            $this->assertSame($expectedRequestedHeal, $rule->healingCalls[0]['amount'], $name);
            $this->assertSame(100 + $expectedActualHeal, $attacker->hp, $name);
        }
    }

    public function test_burning_life_scales_every_existing_attack_route_after_route_resolution(): void
    {
        $cases = [
            'STR' => ['active', 'PHYSICAL_DAMAGE', 'physical', null, null, 350, 'physical'],
            'MAG' => ['active', 'MAGICAL_DAMAGE', 'magical', null, null, 450, 'magical'],
            'HYBRID' => ['active', 'HYBRID_DAMAGE', 'hybrid', null, null, 400, 'physical'],
            'v2 AGI' => ['job_art', 'PHYSICAL_DAMAGE', 'physical', 'agi', 600, 300, 'physical'],
            'v2 LUK' => ['job_art', 'MAGICAL_DAMAGE', 'magical', 'luk', 500, 250, 'magical'],
        ];

        foreach ($cases as $name => [$skillType, $template, $damageType, $explicitStat, $existingAttack, $expectedAttack, $expectedCategory]) {
            $support = new PvPRoomRuleJobArtSupportStub();
            $support->existingDamageStatOverrides['attack'] = $existingAttack;
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $service = $this->harness($support, $calculator);
            [$attacker, $defender] = $this->actors(
                defenderHp: 10_000,
                attackerStr: 700,
                attackerMag: 900,
                attackerLuk: 500,
            );
            $attacker->agi = $attacker->baseAgi = 600;
            $state = new BattleState($attacker, $defender, 'pvp');
            $rule = new BurningLifePvPRoomRule();
            $service->bindRule($state, $rule);
            $rule->onBattleStart($attacker, $defender, $state);
            $skill = $this->skill($skillType, $template, $damageType, 11_100 + count($calculator->rankDamageCalls), [
                'hybrid_scaling' => 'average',
            ]);
            if ($explicitStat !== null) {
                $skill->setAttribute('job_art_v2_attack_stat', $explicitStat);
            }
            $rawStats = [$attacker->str, $attacker->mag, $attacker->agi, $attacker->luk];

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertCount(1, $calculator->rankDamageCalls, $name);
            $this->assertSame($expectedCategory, $calculator->rankDamageCalls[0]['attack_type'], $name);
            $this->assertSame($expectedAttack, $calculator->rankDamageCalls[0]['attack'], $name);
            $this->assertSame(140, $calculator->rankDamageCalls[0]['def'], $name);
            $this->assertSame(140, $calculator->rankDamageCalls[0]['spr'], $name);
            $this->assertSame($rawStats, [$attacker->str, $attacker->mag, $attacker->agi, $attacker->luk], $name);
        }
    }

    public function test_burning_life_rechecks_defense_stack_between_multi_hits(): void
    {
        $calculator = new PvPRoomRuleDamageCalculatorSpy();
        $calculator->damage = 20;
        $service = $this->harness(calculator: $calculator);
        [$attacker, $defender] = $this->actors(defenderHp: 1_000, defenderMaxHp: 1_000);
        $state = new BattleState($attacker, $defender, 'pvp');
        $rule = new BurningLifePvPRoomRule();
        $service->bindRule($state, $rule);
        $rule->onBattleStart($attacker, $defender, $state);
        $rule->onActualHpLoss($attacker, $defender, $state, 140, DamageSourceType::NORMAL_ATTACK);
        $skill = $this->skill('job_art', 'PHYSICAL_DAMAGE', 'physical', 11_200, [
            'hit_count' => 2,
        ]);

        $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

        $this->assertCount(2, $calculator->rankDamageCalls);
        $this->assertSame([140, 132], array_column($calculator->rankDamageCalls, 'def'));
        $this->assertSame([140, 132], array_column($calculator->rankDamageCalls, 'spr'));
    }

    public function test_burning_life_reduces_every_legacy_hp_recovery_route_at_stack_five(): void
    {
        $cases = [
            'heal_percent' => [$this->skill('active', 'SUPPORT', 'support', 11_301, [
                'power_multiplier' => 0,
                'hit_count' => 0,
                'heal_percent' => 10,
            ]), 60],
            'HEAL' => [$this->skill('job_art', 'HEAL', 'heal', 11_302, [
                'power' => 100,
                'power_multiplier' => 0,
                'hit_count' => 0,
            ]), 60],
            'HEAL_CLEANSE' => [$this->skill('job_art', 'HEAL_CLEANSE', 'heal', 11_303, [
                'power' => 100,
                'power_multiplier' => 0,
                'hit_count' => 0,
            ]), 60],
            'DRAIN' => [$this->skill('job_art', 'DRAIN', 'physical', 11_304, [
                'drain_hp_rate' => 0.5,
            ]), 30],
        ];

        foreach ($cases as $name => [$skill, $expectedHeal]) {
            $calculator = new PvPRoomRuleDamageCalculatorSpy();
            $calculator->damage = 100;
            $service = $this->harness(calculator: $calculator);
            [$attacker, $defender] = $this->actors(attackerHp: 100, attackerMaxHp: 1_000, defenderHp: 1_000);
            $state = new BattleState($attacker, $defender, 'pvp');
            $rule = new BurningLifePvPRoomRule();
            $service->bindRule($state, $rule);
            $rule->onBattleStart($attacker, $defender, $state);
            $rule->onActualHpLoss($defender, $attacker, $state, 750, DamageSourceType::NORMAL_ATTACK);

            $service->runSkill($attacker, $defender, $state, $skill, HitResult::HIT);

            $this->assertSame(100 + $expectedHeal, $attacker->hp, $name);
        }
    }

    public function test_burning_life_applies_to_role_healing_and_conversion_refunds_but_not_sp_recovery(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $service = $this->harness($support);
        [$attacker, $defender] = $this->actors(attackerHp: 100, attackerMaxHp: 1_000);
        $attacker->mp = 0;
        $state = new BattleState($attacker, $defender, 'pvp');
        $rule = new BurningLifePvPRoomRule();
        $service->bindRule($state, $rule);
        $rule->onBattleStart($attacker, $defender, $state);
        $rule->onActualHpLoss($defender, $attacker, $state, 750, DamageSourceType::NORMAL_ATTACK);
        $roleHeal = $this->skill('job_art', 'HEAL', 'heal', 11_401);

        $this->assertSame(60, $support->resolveRegisteredHealing($state, $attacker, $roleHeal, 100, true));
        $this->assertSame(160, $attacker->hp);
        $this->assertSame(60, $support->resolveRegisteredHealing($state, $attacker, $roleHeal, 100, false));
        $this->assertSame(220, $attacker->hp);

        $spRecovery = $this->skill('active', 'SUPPORT', 'support', 11_402, [
            'power_multiplier' => 0,
            'hit_count' => 0,
            'mp_recover_percent' => 100,
        ]);
        $service->runSkill($attacker, $defender, $state, $spRecovery, HitResult::HIT);
        $this->assertSame(100, $attacker->mp);
    }

    public function test_registered_role_healing_resolver_uses_each_states_own_rule(): void
    {
        $support = new PvPRoomRuleJobArtSupportStub();
        $service = $this->harness($support);
        [$firstActor, $firstTarget] = $this->actors(attackerHp: 100, attackerMaxHp: 500);
        [$secondActor, $secondTarget] = $this->actors(attackerHp: 100, attackerMaxHp: 500);
        $firstState = new BattleState($firstActor, $firstTarget, 'pvp');
        $secondState = new BattleState($secondActor, $secondTarget, 'pvp');
        $firstRule = new SpyPvPRoomRule();
        $firstRule->halveHealing = true;
        $secondRule = new SpyPvPRoomRule();
        $service->bindRule($firstState, $firstRule);
        $service->bindRule($secondState, $secondRule);
        $skill = $this->skill('job_art', 'HEAL', 'heal', 12_001);

        $this->assertSame(50, $support->resolveRegisteredHealing($firstState, $firstActor, $skill, 100, false));
        $this->assertSame(100, $support->resolveRegisteredHealing($secondState, $secondActor, $skill, 100, false));
        $this->assertSame(150, $firstActor->hp);
        $this->assertSame(200, $secondActor->hp);
        $this->assertCount(1, $firstRule->healingCalls);
        $this->assertCount(1, $secondRule->healingCalls);
    }

    private function harness(
        ?PvPRoomRuleJobArtSupportStub $support = null,
        ?PvPRoomRuleDamageCalculatorSpy $calculator = null,
    ): PvPRoomRuleBattleHarness {
        return new PvPRoomRuleBattleHarness(
            Mockery::mock(CharacterStatusService::class),
            $calculator ?? new PvPRoomRuleDamageCalculatorSpy(),
            $support ?? new PvPRoomRuleJobArtSupportStub(),
            new DamageApplicationService(),
        );
    }

    private function loopHarness(
        PvPRoomRuleJobArtSupportStub $support,
        SpyPvPRoomRule $rule,
        int $attackerAgi,
        int $defenderAgi,
    ): PvPRoomRuleLoopHarness {
        $status = Mockery::mock(CharacterStatusService::class);
        $status->shouldReceive('getFinalStats')->twice()->andReturn(
            $this->stats(agi: $attackerAgi),
            $this->stats(agi: $defenderAgi),
        );

        return new PvPRoomRuleLoopHarness(
            $status,
            new PvPRoomRuleDamageCalculatorSpy(),
            $support,
            new DamageApplicationService(),
            $rule,
        );
    }

    /** @return array{BattleActor, BattleActor} */
    private function actors(
        int $attackerHp = 1_000,
        int $defenderHp = 1_000,
        ?int $attackerMaxHp = null,
        ?int $defenderMaxHp = null,
        int $attackerAgi = 100,
        int $defenderAgi = 100,
        int $attackerStr = 100,
        int $attackerMag = 100,
        int $attackerLuk = 100,
        ?string $attackerNormalAttackType = null,
    ): array {
        return [
            $this->actor(
                'attacker',
                $attackerHp,
                $attackerMaxHp ?? $attackerHp,
                $attackerAgi,
                $attackerStr,
                $attackerMag,
                $attackerLuk,
                $attackerNormalAttackType,
            ),
            $this->actor('defender', $defenderHp, $defenderMaxHp ?? $defenderHp, $defenderAgi),
        ];
    }

    private function actor(
        string $name,
        int $hp,
        int $maxHp,
        int $agi = 100,
        int $str = 100,
        int $mag = 100,
        int $luk = 100,
        ?string $normalAttackType = null,
    ): BattleActor {
        $stats = [
            'hp' => $hp,
            'max_hp' => $maxHp,
            'mp' => 100,
            'max_mp' => 100,
            'str' => $str,
            'def' => 100,
            'agi' => $agi,
            'mag' => $mag,
            'spr' => 100,
            'luk' => $luk,
        ];
        if ($normalAttackType !== null) {
            $stats['normal_attack_type'] = $normalAttackType;
        }

        return new BattleActor($name, true, $stats);
    }

    /** @return array{max_hp:int,max_mp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int} */
    private function stats(int $agi): array
    {
        return [
            'max_hp' => 1_000,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => $agi,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ];
    }

    /** @param array<string, int|float|string> $overrides */
    private function skill(
        string $skillType,
        string $template,
        string $damageType,
        int $id,
        array $overrides = [],
    ): Skill {
        $skill = new Skill(array_replace([
            'name' => $template,
            'skill_type' => $skillType,
            'effect_template' => $template,
            'damage_type' => $damageType,
            'power' => 100,
            'power_multiplier' => 1.0,
            'hit_count' => 1,
            'heal_percent' => 0,
            'mp_recover_percent' => 0,
            'self_damage_percent' => 0,
            'drain_hp_rate' => 0,
        ], $overrides));
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function character(string $name): Character
    {
        $character = new Character(['name' => $name]);
        $character->setRelation('currentJob', null);

        return $character;
    }
}

final class SpyPvPRoomRule implements PvPRoomRuleInterface
{
    public bool $invertInitiative = false;
    public bool $halveHealing = false;
    public bool $killActorOnActionEnd = false;
    public ?int $finalDamageResult = null;
    public ?int $lukPowerContributionResult = null;

    /** @var array{attack:?int,def:?int,spr:?int} */
    public array $damageStatReplacements = ['attack' => null, 'def' => null, 'spr' => null];

    public int $battleStartCalls = 0;

    /** @var list<string> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $initiativeCalls = [];

    /** @var list<array<string, mixed>> */
    public array $damageStatCalls = [];

    /** @var list<array{contribution:int,skill:Skill}> */
    public array $lukPowerContributionCalls = [];

    /** @var list<array<string, mixed>> */
    public array $finalDamageCalls = [];

    /** @var list<array<string, mixed>> */
    public array $healingCalls = [];

    /** @var list<array<string, mixed>> */
    public array $actualHpLossCalls = [];

    /** @var list<array{actor:string,opponent:string}> */
    public array $actionEndCalls = [];

    public function onBattleStart(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $this->battleStartCalls++;
        $this->events[] = 'battle_start';
    }

    public function modifyInitiative(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        int $attackerSpeed,
        int $defenderSpeed,
        bool $defaultAttackerFirst,
    ): bool {
        $this->initiativeCalls[] = [
            'attacker_speed' => $attackerSpeed,
            'defender_speed' => $defenderSpeed,
            'default_attacker_first' => $defaultAttackerFirst,
        ];
        $this->events[] = 'initiative';

        return $this->invertInitiative ? ! $defaultAttackerFirst : $defaultAttackerFirst;
    }

    public function modifyLukPowerContribution(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $contribution,
    ): int {
        $this->lukPowerContributionCalls[] = [
            'contribution' => $contribution,
            'skill' => $skill,
        ];

        return $this->lukPowerContributionResult ?? $contribution;
    }

    public function modifyDamageStatOverrides(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        string $attackType,
        DamageSourceType $sourceType,
        ?Skill $skill,
        array $overrides,
    ): array {
        $this->damageStatCalls[] = [
            'attack_type' => $attackType,
            'source_type' => $sourceType,
            'skill' => $skill,
            'incoming' => $overrides,
        ];

        return array_replace($overrides, array_filter(
            $this->damageStatReplacements,
            static fn (?int $value): bool => $value !== null,
        ));
    }

    public function modifyFinalDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        int $hitIndex = 1,
        int $hitCount = 1,
    ): int {
        $this->finalDamageCalls[] = [
            'damage' => $damage,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'hit_index' => $hitIndex,
            'hit_count' => $hitCount,
        ];

        return $this->finalDamageResult ?? $damage;
    }

    public function modifyHealing(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $amount,
        int|string|null $sourceId = null,
    ): int {
        $this->healingCalls[] = [
            'amount' => $amount,
            'source_id' => $sourceId,
        ];

        return $this->halveHealing ? intdiv($amount, 2) : $amount;
    }

    public function onActualHpLoss(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $actualHpLoss,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
    ): void {
        $this->actualHpLossCalls[] = [
            'actual_hp_loss' => $actualHpLoss,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    public function onActionEnd(BattleActor $actor, BattleActor $opponent, BattleState $state): void
    {
        $this->actionEndCalls[] = ['actor' => $actor->name, 'opponent' => $opponent->name];
        $this->events[] = "action_end:{$actor->name}";
        if ($this->killActorOnActionEnd) {
            $actor->takeDamage($actor->hp);
        }
    }
}

class PvPRoomRuleBattleHarness extends PvPBattleService
{
    public function bindRule(BattleState $state, PvPRoomRuleInterface $rule): void
    {
        $this->associateRoomRule($state, $rule);
    }

    public function ruleForTest(BattleState $state): PvPRoomRuleInterface
    {
        return $this->roomRuleFor($state);
    }

    public function runNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $this->executeNormalAttack($attacker, $defender, $state);
    }

    public function runPhysicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $this->executePhysicalAttack($attacker, $defender, $state);
    }

    public function runSkill(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult = null,
    ): void {
        $this->executeSkillAction($attacker, $defender, $state, $skill, false, $hitResult);
    }

    public function runResolvedDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
    ): ?DamageApplicationResult {
        return $this->applyResolvedDamage($source, $target, $state, $damage, $sourceType, $sourceId);
    }

    public function runResolvedHealing(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $amount,
        int|string|null $sourceId = null,
    ): int {
        return $this->applyResolvedHealing($source, $target, $state, $amount, $sourceId);
    }

    public function runBaseInitiative(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        bool $usesRoleSpeed,
    ): bool {
        return $this->resolveBaseInitiative($attacker, $defender, $state, $usesRoleSpeed);
    }
}

final class PvPRoomRuleLoopHarness extends PvPRoomRuleBattleHarness
{
    /** @var list<string> */
    public array $actionActors = [];

    public function __construct(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
        JobArtBattleSupportService $jobArtBattleSupport,
        DamageApplicationService $damageApplicationService,
        private readonly SpyPvPRoomRule $eventRule,
    ) {
        parent::__construct($statusService, $damageCalculator, $jobArtBattleSupport, $damageApplicationService);
    }

    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state, bool $tickCooldowns = true): void
    {
        $this->actionActors[] = $attacker->name;
        $this->eventRule->events[] = "execute:{$attacker->name}";
    }
}

final class PvPSpeedRoomRuleLoopHarness extends PvPRoomRuleBattleHarness
{
    /** @var list<string> */
    public array $actionActors = [];

    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state, bool $tickCooldowns = true): void
    {
        $this->actionActors[] = $attacker->name;
        $defender->takeDamage($defender->hp);
    }
}

final class PvPRoomRuleDamageCalculatorSpy extends DamageCalculator
{
    public int $damage = 100;
    public bool $useRealCalculation = false;
    public bool $mutateAttackerAgiAfterFirstCall = false;

    /** @var list<array{attack_type:string,skill_power:int,attack:?int,def:?int,spr:?int,is_skill:bool,hit_count:int,minimum_damage_guarantee_enabled:bool,damage_cap_enabled:bool,base_damage_multiplier:float,additional_defense_ignore_rate:float}> */
    public array $rankDamageCalls = [];

    /** @var list<array{attacker:int,defender:int}> */
    public array $hitAgiCalls = [];

    public function isHit(
        BattleActor $attacker,
        BattleActor $defender,
        int $skillAccuracy = 100,
        float $agiFactor = 0.5,
        int $minHitRate = 70,
        int $maxHitRate = 98,
        float $accuracyDelta = 0.0,
    ): bool {
        $this->hitAgiCalls[] = [
            'attacker' => $attacker->effectiveAgi(),
            'defender' => $defender->effectiveAgi(),
        ];

        return true;
    }

    public function isRankBattleCritical(
        BattleActor $attacker,
        BattleActor $defender,
        float $bonusRate = 0.0,
    ): bool {
        return false;
    }

    public function calculateRankBattleDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        int $skillPower = 100,
        bool $isCritical = false,
        float $affinityMultiplier = 1.0,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $overrideSpr = null,
        bool $isSkill = false,
        int $hitCount = 1,
        bool $minimumDamageGuaranteeEnabled = true,
        bool $damageCapEnabled = true,
        float $baseDamageMultiplier = 1.0,
        float $additionalDefenseIgnoreRate = 0.0,
    ): int {
        $this->rankDamageCalls[] = [
            'attack_type' => $attackType,
            'skill_power' => $skillPower,
            'attack' => $overrideAtk,
            'def' => $overrideDef,
            'spr' => $overrideSpr,
            'is_skill' => $isSkill,
            'hit_count' => $hitCount,
            'minimum_damage_guarantee_enabled' => $minimumDamageGuaranteeEnabled,
            'damage_cap_enabled' => $damageCapEnabled,
            'base_damage_multiplier' => $baseDamageMultiplier,
            'additional_defense_ignore_rate' => $additionalDefenseIgnoreRate,
        ];

        if ($this->mutateAttackerAgiAfterFirstCall && count($this->rankDamageCalls) === 1) {
            $attacker->agi = 1;
        }

        if ($this->useRealCalculation) {
            return parent::calculateRankBattleDamage(
                $attacker,
                $defender,
                $attackType,
                $skillPower,
                $isCritical,
                $affinityMultiplier,
                $overrideAtk,
                $overrideDef,
                $overrideSpr,
                $isSkill,
                $hitCount,
                $minimumDamageGuaranteeEnabled,
                $damageCapEnabled,
                $baseDamageMultiplier,
                $additionalDefenseIgnoreRate,
            );
        }

        return $this->damage;
    }
}

final class PvPRoomRuleJobArtSupportStub extends JobArtBattleSupportService
{
    public bool $damageApplicationEnabled = false;
    public bool $roleEffectsEnabled = false;
    public bool $rerollInitiative = false;
    public ?int $fieldDamageResult = null;
    public ?int $jobArtDamageResult = null;
    public int $completedHpHeals = 0;

    /** @var array{attack:?int,def:?int,spr:?int,applied_ignore_rate?:float} */
    public array $existingDamageStatOverrides = ['attack' => null, 'def' => null, 'spr' => null, 'applied_ignore_rate' => 0.0];

    /** @var list<bool> */
    public array $adjustInitiativeInputs = [];

    /** @var list<int> */
    public array $fieldDamageInputs = [];

    /** @var list<int> */
    public array $jobArtDamageInputs = [];

    /** @var \WeakMap<BattleState, \Closure> */
    private \WeakMap $testHealingResolvers;

    public function __construct()
    {
        $this->testHealingResolvers = new \WeakMap();
    }

    public function attachBossSet(BattleActor $actor, Character $character, string $context = 'champ'): void
    {
    }

    public function registerHpHealingResolver(BattleState $state, \Closure $resolver): void
    {
        $this->testHealingResolvers[$state] = $resolver;
    }

    public function resolveRegisteredHealing(
        BattleState $state,
        BattleActor $actor,
        Skill $skill,
        int $amount,
        bool $applyExistingModifiers,
    ): int {
        return ($this->testHealingResolvers[$state])($actor, $state, $skill, $amount, $applyExistingModifiers);
    }

    public function usesDamageApplication(?BattleActor $source, BattleActor $target): bool
    {
        return $this->damageApplicationEnabled;
    }

    public function usesRoleEffects(BattleActor $actor): bool
    {
        return $this->roleEffectsEnabled;
    }

    public function adjustInitiative(
        BattleActor $firstCandidate,
        BattleActor $secondCandidate,
        bool $firstCandidateWon,
        \Closure $reroll,
    ): bool {
        $this->adjustInitiativeInputs[] = $firstCandidateWon;

        return $this->rerollInitiative ? $reroll() : $firstCandidateWon;
    }

    public function endRound(BattleState $state): array
    {
        return [];
    }

    public function battleHud(BattleState $state): ?array
    {
        return null;
    }

    public function markNormalAttackAction(BattleActor $actor, BattleState $state): void
    {
    }

    public function fieldAccuracyDelta(BattleActor $actor, BattleState $state): float
    {
        return 0.0;
    }

    public function recordNormalAttackResolution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        HitResult $hitResult,
        bool $markAction = true,
    ): ResourceChangeResult {
        return ResourceChangeResult::unchanged();
    }

    public function modifyFieldDamage(
        BattleActor $actor,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
    ): int {
        $this->fieldDamageInputs[] = $damage;

        return $this->fieldDamageResult ?? $damage;
    }

    public function markSkillAction(BattleActor $actor, BattleState $state, Skill $skill): void
    {
    }

    public function isFieldOnlyArt(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        return false;
    }

    public function defenseOverrides(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
    ): array {
        return ['def' => null, 'spr' => null, 'penetration_rate' => null];
    }

    public function damageStatOverrides(BattleActor $attacker, BattleActor $defender, Skill $skill): array
    {
        return $this->existingDamageStatOverrides;
    }

    public function modifyJobArtDamage(BattleActor $actor, BattleState $state, Skill $skill, int $damage): int
    {
        $this->jobArtDamageInputs[] = $damage;

        return $this->jobArtDamageResult ?? $damage;
    }

    public function applyTimedStructuredDebuffs(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        float $rate = 1.0,
    ): ?array {
        return null;
    }

    public function modifyFieldHpHeal(BattleActor $actor, BattleState $state, int $heal): int
    {
        return $heal;
    }

    public function completeFieldHpHeal(BattleActor $actor, BattleState $state): void
    {
        $this->completedHpHeals++;
    }
}
