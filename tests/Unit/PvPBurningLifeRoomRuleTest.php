<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PvPBurningLifeRoomRuleTest extends TestCase
{
    #[DataProvider('stackMultiplierProvider')]
    public function test_each_stack_scales_attack_defense_and_received_hp_healing(
        int $stack,
        int $expectedAttack,
        int $expectedDef,
        int $expectedSpr,
        int $expectedHealing,
    ): void {
        [$attacker, $defender, $state] = $this->battle(
            attackerStr: 200,
            defenderDef: 100,
            defenderSpr: 50,
        );
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);
        $loss = $stack * 150;
        if ($loss > 0) {
            $rule->onActualHpLoss($defender, $attacker, $state, $loss, DamageSourceType::NORMAL_ATTACK);
            $rule->onActualHpLoss($attacker, $defender, $state, $loss, DamageSourceType::NORMAL_ATTACK);
        }

        $this->assertSame([
            'attack' => $expectedAttack,
            'def' => $expectedDef,
            'spr' => $expectedSpr,
        ], $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'physical',
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        ));
        $this->assertSame(
            $expectedHealing,
            $rule->modifyHealing($attacker, $defender, $state, 100),
        );
    }

    public static function stackMultiplierProvider(): array
    {
        return [
            'stack 0' => [0, 100, 140, 70, 100],
            'stack 1' => [1, 130, 132, 66, 92],
            'stack 2' => [2, 160, 124, 62, 84],
            'stack 3' => [3, 190, 116, 58, 76],
            'stack 4' => [4, 220, 108, 54, 68],
            'stack 5' => [5, 250, 100, 50, 60],
        ];
    }

    #[DataProvider('integerThresholdProvider')]
    public function test_stack_thresholds_use_cumulative_actual_hp_loss_and_cap_at_five(
        int $actualHpLoss,
        int $expectedAttack,
    ): void {
        [$attacker, $defender, $state] = $this->battle();
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $rule->onActualHpLoss(
            $defender,
            $attacker,
            $state,
            $actualHpLoss,
            DamageSourceType::JOB_ART,
        );

        $this->assertSame($expectedAttack, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public static function integerThresholdProvider(): array
    {
        return [
            '149 stays at zero' => [149, 50],
            '150 reaches one' => [150, 65],
            '299 stays at one' => [299, 65],
            '300 reaches two' => [300, 80],
            '449 stays at two' => [449, 80],
            '450 reaches three' => [450, 95],
            'one hit can reach three' => [470, 95],
            '600 reaches four' => [600, 110],
            '750 reaches five' => [750, 125],
            '900 remains capped at five' => [900, 125],
        ];
    }

    #[DataProvider('fractionalThresholdProvider')]
    public function test_non_integer_thresholds_do_not_round_down(
        int $actualHpLoss,
        int $expectedAttack,
    ): void {
        [$attacker, $defender, $state] = $this->battle(maxHp: 1_001);
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $rule->onActualHpLoss(
            $defender,
            $attacker,
            $state,
            $actualHpLoss,
            DamageSourceType::NORMAL_ATTACK,
        );

        $this->assertSame($expectedAttack, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public static function fractionalThresholdProvider(): array
    {
        return [
            '150 is below 15 percent of 1001' => [150, 50],
            '151 reaches 15 percent of 1001' => [151, 65],
        ];
    }

    public function test_healing_never_reduces_stack_or_cumulative_hp_loss(): void
    {
        [$attacker, $defender, $state] = $this->battle(attackerHp: 700);
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);
        $rule->onActualHpLoss($defender, $attacker, $state, 300, DamageSourceType::NORMAL_ATTACK);

        $attacker->healHp(300);
        $this->assertSame(80, $this->attackOverride($rule, $attacker, $defender, $state));

        $rule->onActualHpLoss($defender, $attacker, $state, 149, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(80, $this->attackOverride($rule, $attacker, $defender, $state));
        $rule->onActualHpLoss($defender, $attacker, $state, 1, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(95, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public function test_only_opponent_actual_hp_loss_counts_and_recoil_is_excluded(): void
    {
        [$attacker, $defender, $state] = $this->battle();
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $rule->onActualHpLoss(null, $attacker, $state, 1_000, DamageSourceType::DOT);
        $rule->onActualHpLoss($attacker, $attacker, $state, 1_000, DamageSourceType::SELF_DAMAGE);
        $rule->onActualHpLoss($defender, $attacker, $state, 1_000, DamageSourceType::RECOIL);
        $this->assertSame(50, $this->attackOverride($rule, $attacker, $defender, $state));

        $rule->onActualHpLoss($defender, $attacker, $state, 30, DamageSourceType::COUNTER);
        $rule->onActualHpLoss($defender, $attacker, $state, 119, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(50, $this->attackOverride($rule, $attacker, $defender, $state));
        $rule->onActualHpLoss($defender, $attacker, $state, 1, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(65, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public function test_action_end_self_damage_uses_battle_start_max_hp_and_current_hp_after_healing(): void
    {
        [$attacker, $defender, $state] = $this->battle(attackerHp: 400);
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $attacker->healHp(200);
        $attacker->maxHp = 2_000;
        $rule->onActionEnd($attacker, $defender, $state);

        $this->assertSame(568, $attacker->hp, '20 baseline HP damage plus 12 current HP damage is applied.');
    }

    public function test_self_damage_uses_actual_hp_loss_can_kill_and_skips_an_already_dead_actor(): void
    {
        [$attacker, $defender, $state] = $this->battle(attackerHp: 10);
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $rule->onActionEnd($attacker, $defender, $state);
        $this->assertSame(0, $attacker->hp);
        $damageTakenAfterDeath = $attacker->totalDamageTaken;
        $rule->onActionEnd($attacker, $defender, $state);
        $this->assertSame($damageTakenAfterDeath, $attacker->totalDamageTaken);

        $rule->onActualHpLoss($defender, $attacker, $state, 139, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(50, $this->attackOverride($rule, $attacker, $defender, $state));
        $rule->onActualHpLoss($defender, $attacker, $state, 1, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(65, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public function test_burning_self_damage_is_recorded_exactly_once(): void
    {
        [$attacker, $defender, $state] = $this->battle(attackerHp: 500);
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $rule->onActionEnd($attacker, $defender, $state);
        $this->assertSame(470, $attacker->hp);
        $rule->onActualHpLoss($defender, $attacker, $state, 119, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(50, $this->attackOverride($rule, $attacker, $defender, $state));
        $rule->onActualHpLoss($defender, $attacker, $state, 1, DamageSourceType::NORMAL_ATTACK);
        $this->assertSame(65, $this->attackOverride($rule, $attacker, $defender, $state));
    }

    public function test_one_rule_instance_isolated_state_by_battle_and_actor_and_resets_on_battle_start(): void
    {
        [$firstAttacker, $firstDefender, $firstState] = $this->battle();
        [$secondAttacker, $secondDefender, $secondState] = $this->battle();
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($firstAttacker, $firstDefender, $firstState);
        $rule->onBattleStart($secondAttacker, $secondDefender, $secondState);

        $rule->onActualHpLoss($firstDefender, $firstAttacker, $firstState, 750, DamageSourceType::JOB_ART);
        $this->assertSame(125, $this->attackOverride($rule, $firstAttacker, $firstDefender, $firstState));
        $this->assertSame(50, $this->attackOverride($rule, $firstDefender, $firstAttacker, $firstState));
        $this->assertSame(50, $this->attackOverride($rule, $secondAttacker, $secondDefender, $secondState));

        $rule->onBattleStart($firstAttacker, $firstDefender, $firstState);
        $this->assertSame(50, $this->attackOverride($rule, $firstAttacker, $firstDefender, $firstState));
    }

    #[DataProvider('attackRouteProvider')]
    public function test_every_existing_attack_stat_override_route_receives_the_same_multiplier(
        string $attackType,
        ?string $explicitAttackStat,
    ): void {
        [$attacker, $defender, $state] = $this->battle();
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);
        $skill = new Skill;
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
        }

        $overrides = $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            $attackType,
            DamageSourceType::JOB_ART,
            $skill,
            ['attack' => 1_001, 'def' => 500, 'spr' => 300],
        );

        $this->assertSame(500, $overrides['attack']);
        $this->assertSame(700, $overrides['def']);
        $this->assertSame(420, $overrides['spr']);
    }

    public static function attackRouteProvider(): array
    {
        return [
            'STR' => ['physical', null],
            'MAG' => ['magical', null],
            'HYBRID' => ['hybrid', null],
            'v2 AGI' => ['physical', 'agi'],
            'v2 LUK' => ['magical', 'luk'],
            'v2 DEF' => ['physical', 'def'],
            'v2 SPR' => ['magical', 'spr'],
        ];
    }

    public function test_null_attack_and_defense_overrides_use_existing_effective_stats(): void
    {
        [$attacker, $defender, $state] = $this->battle(
            attackerStr: 700,
            attackerMag: 900,
            defenderDef: 500,
            defenderSpr: 300,
        );
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);

        $physical = $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'physical',
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        );
        $magical = $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'magical',
            DamageSourceType::JOB_SKILL,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        );

        $this->assertSame(['attack' => 350, 'def' => 700, 'spr' => 420], $physical);
        $this->assertSame(['attack' => 450, 'def' => 700, 'spr' => 420], $magical);
    }

    public function test_healing_uses_target_stack_and_floors_without_changing_sp_or_actor_stats(): void
    {
        [$attacker, $defender, $state] = $this->battle(
            attackerStr: 701,
            attackerMag: 901,
            defenderDef: 501,
            defenderSpr: 301,
        );
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);
        $rawStats = [
            $attacker->str,
            $attacker->mag,
            $attacker->def,
            $attacker->spr,
            $attacker->agi,
            $attacker->luk,
        ];
        $attacker->mp = 10;
        $rule->onActualHpLoss($attacker, $defender, $state, 750, DamageSourceType::NORMAL_ATTACK);

        $this->assertSame(60, $rule->modifyHealing($attacker, $defender, $state, 101));
        $attacker->mp += 100;
        $this->assertSame(110, $attacker->mp, 'SP recovery does not use the HP-healing hook.');
        $this->assertSame($rawStats, [
            $attacker->str,
            $attacker->mag,
            $attacker->def,
            $attacker->spr,
            $attacker->agi,
            $attacker->luk,
        ]);
    }

    public function test_initiative_luk_power_and_final_damage_hooks_are_no_ops(): void
    {
        [$attacker, $defender, $state] = $this->battle();
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($attacker, $defender, $state);
        $skill = new Skill;

        $this->assertFalse($rule->modifyInitiative($attacker, $defender, $state, 80, 100, false));
        $this->assertTrue($rule->modifyInitiative($attacker, $defender, $state, 80, 100, true));
        $this->assertSame(37, $rule->modifyLukPowerContribution($attacker, $defender, $state, $skill, 37));
        $this->assertSame(123, $rule->modifyFinalDamage(
            $attacker,
            $defender,
            $state,
            123,
            DamageSourceType::NORMAL_ATTACK,
        ));
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(
        int $maxHp = 1_000,
        ?int $attackerHp = null,
        int $attackerStr = 100,
        int $attackerMag = 100,
        int $defenderDef = 100,
        int $defenderSpr = 100,
    ): array {
        $attacker = $this->actor(
            'attacker',
            $maxHp,
            $attackerHp ?? $maxHp,
            str: $attackerStr,
            mag: $attackerMag,
        );
        $defender = $this->actor(
            'defender',
            $maxHp,
            $maxHp,
            def: $defenderDef,
            spr: $defenderSpr,
        );

        return [$attacker, $defender, new BattleState($attacker, $defender, 'pvp')];
    }

    private function actor(
        string $name,
        int $maxHp,
        int $hp,
        int $str = 100,
        int $def = 100,
        int $agi = 100,
        int $mag = 100,
        int $spr = 100,
        int $luk = 100,
    ): BattleActor {
        return new BattleActor($name, true, [
            'max_hp' => $maxHp,
            'hp' => $hp,
            'max_mp' => 200,
            'mp' => 200,
            'str' => $str,
            'def' => $def,
            'agi' => $agi,
            'mag' => $mag,
            'spr' => $spr,
            'luk' => $luk,
        ]);
    }

    private function attackOverride(
        BurningLifePvPRoomRule $rule,
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
    ): int {
        return $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'physical',
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        )['attack'];
    }
}
