<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\JobArtV2TimedEffectState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PvPSpeedRoomRulesTest extends TestCase
{
    #[DataProvider('divineSpeedDamageProvider')]
    public function test_divine_speed_uses_continuous_effective_agi_advantage_after_the_existing_cap(
        int $sourceAgi,
        int $targetAgi,
        int $damage,
        int $expected,
    ): void {
        [$source, $target, $state] = $this->battle($sourceAgi, $targetAgi);

        $this->assertSame($expected, (new DivineSpeedPvPRoomRule())->modifyFinalDamage(
            $source,
            $target,
            $state,
            $damage,
            DamageSourceType::NORMAL_ATTACK,
        ));
    }

    public static function divineSpeedDamageProvider(): array
    {
        return [
            'equal AGI' => [100, 100, 100, 100],
            'lower AGI' => [90, 100, 100, 100],
            '20 percent advantage' => [120, 100, 100, 108],
            '50 percent advantage' => [150, 100, 100, 120],
            '100 percent advantage' => [200, 100, 100, 140],
            '150 percent advantage reaches cap' => [250, 100, 100, 160],
            '200 percent advantage remains capped' => [300, 100, 100, 160],
            '22 percent advantage is continuous rather than stepped' => [122, 100, 1_000, 1_088],
            'post-damage multiplier uses the existing floor convention' => [122, 100, 101, 109],
        ];
    }

    #[DataProvider('reverseTimeDamageProvider')]
    public function test_reverse_time_uses_exact_ten_percent_lower_effective_agi_steps(
        int $sourceAgi,
        int $targetAgi,
        int $damage,
        int $expected,
    ): void {
        [$source, $target, $state] = $this->battle($sourceAgi, $targetAgi);

        $this->assertSame($expected, (new ReverseTimePvPRoomRule())->modifyFinalDamage(
            $source,
            $target,
            $state,
            $damage,
            DamageSourceType::JOB_ART,
        ));
    }

    public static function reverseTimeDamageProvider(): array
    {
        return [
            'equal AGI' => [100, 100, 100, 100],
            '9 percent lower' => [91, 100, 100, 100],
            'exactly 10 percent lower' => [90, 100, 100, 108],
            '19 percent lower' => [81, 100, 100, 108],
            'exactly 20 percent lower' => [80, 100, 100, 116],
            '30 percent lower' => [70, 100, 100, 124],
            '40 percent lower' => [60, 100, 100, 132],
            '50 percent lower reaches cap' => [50, 100, 100, 140],
            '90 percent lower remains capped' => [10, 100, 100, 140],
            'higher AGI' => [110, 100, 100, 100],
            'post-damage multiplier uses the existing floor convention' => [90, 100, 107, 115],
        ];
    }

    public function test_divine_speed_preserves_the_default_initiative_result(): void
    {
        [$attacker, $defender, $state] = $this->battle(80, 100);
        $rule = new DivineSpeedPvPRoomRule();

        $this->assertFalse($rule->modifyInitiative($attacker, $defender, $state, 80, 100, false));
        $this->assertTrue($rule->modifyInitiative($attacker, $defender, $state, 80, 100, true));
    }

    #[DataProvider('reverseTimeInitiativeProvider')]
    public function test_reverse_time_gives_initiative_to_the_lower_speed_side_and_attacker_on_a_tie(
        int $attackerSpeed,
        int $defenderSpeed,
        bool $expected,
    ): void {
        [$attacker, $defender, $state] = $this->battle($attackerSpeed, $defenderSpeed);

        $this->assertSame($expected, (new ReverseTimePvPRoomRule())->modifyInitiative(
            $attacker,
            $defender,
            $state,
            $attackerSpeed,
            $defenderSpeed,
            !$expected,
        ));
    }

    public static function reverseTimeInitiativeProvider(): array
    {
        return [
            'attacker is slower' => [80, 100, true],
            'attacker is faster' => [120, 100, false],
            'tie favors attacker' => [100, 100, true],
        ];
    }

    public function test_both_rules_leave_non_opponent_and_recoil_damage_unchanged(): void
    {
        foreach ($this->damageRuleCases() as [$rule, $sourceAgi, $targetAgi]) {
            [$source, $target, $state] = $this->battle($sourceAgi, $targetAgi);

            $this->assertSame(0, $rule->modifyFinalDamage(
                $source,
                $target,
                $state,
                0,
                DamageSourceType::NORMAL_ATTACK,
            ));
            $this->assertSame(-10, $rule->modifyFinalDamage(
                $source,
                $target,
                $state,
                -10,
                DamageSourceType::NORMAL_ATTACK,
            ));
            $this->assertSame(100, $rule->modifyFinalDamage(
                null,
                $target,
                $state,
                100,
                DamageSourceType::DOT,
            ));
            $this->assertSame(100, $rule->modifyFinalDamage(
                $source,
                $source,
                $state,
                100,
                DamageSourceType::SELF_DAMAGE,
            ));
            $this->assertSame(100, $rule->modifyFinalDamage(
                $source,
                $target,
                $state,
                100,
                DamageSourceType::RECOIL,
            ));
        }
    }

    public function test_both_rules_apply_opponent_damage_once_per_hit_including_counters(): void
    {
        foreach ($this->damageRuleCases() as [$rule, $sourceAgi, $targetAgi, $expected]) {
            [$source, $target, $state] = $this->battle($sourceAgi, $targetAgi);

            for ($hitIndex = 1; $hitIndex <= 3; $hitIndex++) {
                $this->assertSame($expected, $rule->modifyFinalDamage(
                    $source,
                    $target,
                    $state,
                    100,
                    DamageSourceType::COUNTER,
                    sourceId: 7_001,
                    hitIndex: $hitIndex,
                    hitCount: 3,
                ));
            }
        }
    }

    public function test_both_rules_read_effective_agi_again_for_each_damage_event_without_mutating_raw_agi(): void
    {
        [$source, $target, $state] = $this->battle(100, 100);
        $divineSpeed = new DivineSpeedPvPRoomRule();
        $reverseTime = new ReverseTimePvPRoomRule();

        $this->assertSame(100, $divineSpeed->modifyFinalDamage(
            $source,
            $target,
            $state,
            100,
            DamageSourceType::JOB_ART,
        ));

        $source->replaceJobArtV2TimedEffect($this->agiEffect('speed-up', 0.50));
        $this->assertSame(150, $source->effectiveAgi());
        $this->assertSame(120, $divineSpeed->modifyFinalDamage(
            $source,
            $target,
            $state,
            100,
            DamageSourceType::JOB_ART,
        ));

        $source->removeJobArtV2TimedEffect('speed-up');
        $source->replaceJobArtV2TimedEffect($this->agiEffect('slow-down', -0.20));
        $this->assertSame(80, $source->effectiveAgi());
        $this->assertSame(116, $reverseTime->modifyFinalDamage(
            $source,
            $target,
            $state,
            100,
            DamageSourceType::JOB_ART,
        ));

        $this->assertSame(100, $source->agi);
        $this->assertSame(100, $target->agi);
    }

    public function test_effective_agi_is_clamped_to_zero_and_zero_denominators_are_safe(): void
    {
        $divineSpeed = new DivineSpeedPvPRoomRule();
        $reverseTime = new ReverseTimePvPRoomRule();

        $negative = new PvPSpeedRoomRuleEffectiveAgiActor('negative', -10);
        $zero = new PvPSpeedRoomRuleEffectiveAgiActor('zero', 0);
        $positive = new PvPSpeedRoomRuleEffectiveAgiActor('positive', 10);

        $this->assertSame(100, $divineSpeed->modifyFinalDamage(
            $negative,
            $zero,
            new BattleState($negative, $zero, 'pvp'),
            100,
            DamageSourceType::NORMAL_ATTACK,
        ));
        $this->assertSame(160, $divineSpeed->modifyFinalDamage(
            $positive,
            $negative,
            new BattleState($positive, $negative, 'pvp'),
            100,
            DamageSourceType::NORMAL_ATTACK,
        ));
        $this->assertSame(140, $reverseTime->modifyFinalDamage(
            $negative,
            $positive,
            new BattleState($negative, $positive, 'pvp'),
            100,
            DamageSourceType::NORMAL_ATTACK,
        ));
    }

    public function test_non_speed_hooks_are_no_ops_for_both_rules(): void
    {
        foreach ([new DivineSpeedPvPRoomRule(), new ReverseTimePvPRoomRule()] as $rule) {
            [$source, $target, $state] = $this->battle(120, 100);
            $overrides = ['attack' => 111, 'def' => 222, 'spr' => 333];

            $rule->onBattleStart($source, $target, $state);
            $this->assertSame(50, $rule->modifyLukPowerContribution(
                $source,
                $target,
                $state,
                new Skill(),
                50,
            ));
            $this->assertSame($overrides, $rule->modifyDamageStatOverrides(
                $source,
                $target,
                $state,
                'physical',
                DamageSourceType::JOB_ART,
                null,
                $overrides,
            ));
            $this->assertSame(77, $rule->modifyHealing($source, $target, $state, 77, 8_001));
            $rule->onActualHpLoss($source, $target, $state, 55, DamageSourceType::JOB_ART, 8_001);
            $rule->onActionEnd($source, $target, $state);

            $this->assertSame(1_000, $source->hp);
            $this->assertSame(1_000, $target->hp);
            $this->assertSame([], $state->logs);
        }
    }

    /** @return list<array{PvPRoomRuleInterface, int, int, int}> */
    private function damageRuleCases(): array
    {
        return [
            [new DivineSpeedPvPRoomRule(), 150, 100, 120],
            [new ReverseTimePvPRoomRule(), 80, 100, 116],
        ];
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $sourceAgi, int $targetAgi): array
    {
        $source = $this->actor('source', $sourceAgi);
        $target = $this->actor('target', $targetAgi);

        return [$source, $target, new BattleState($source, $target, 'pvp')];
    }

    private function actor(string $name, int $agi): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => $agi,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }

    private function agiEffect(string $key, float $rate): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: ['agi' => $rate],
            appliedRound: 1,
            remainingRounds: 3,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: abs($rate * 100),
        );
    }
}

final class PvPSpeedRoomRuleEffectiveAgiActor extends BattleActor
{
    public function __construct(string $name, private readonly int $effectiveAgiValue)
    {
        parent::__construct($name, true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'agi' => 100,
        ]);
    }

    public function effectiveAgi(): int
    {
        return $this->effectiveAgiValue;
    }
}
