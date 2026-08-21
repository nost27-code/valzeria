<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\NullPvPRoomRule;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\MiraclePvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PvPMiracleRoomRuleTest extends TestCase
{
    #[DataProvider('lukAttackPowerProvider')]
    public function test_miracle_uses_floor_of_non_negative_effective_luk_times_one_point_two_five(
        int $effectiveLuk,
        int $expectedAttack,
    ): void {
        $attacker = new PvPMiracleEffectiveLukActor('attacker', $effectiveLuk);
        $defender = $this->actor();
        $state = new BattleState($attacker, $defender, 'pvp');

        $this->assertSame([
            'attack' => $expectedAttack,
            'def' => 600,
            'spr' => 400,
        ], (new MiraclePvPRoomRule())->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'physical',
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => 1_800, 'def' => 600, 'spr' => 400],
        ));
    }

    public static function lukAttackPowerProvider(): array
    {
        return [
            'zero' => [0, 0],
            'eighty' => [80, 100],
            'one hundred' => [100, 125],
            'floor one hundred one' => [101, 126],
            'two hundred' => [200, 250],
            'floor large value' => [2_631, 3_288],
            'negative clamps to zero' => [-20, 0],
        ];
    }

    #[DataProvider('attackRouteProvider')]
    public function test_miracle_overrides_every_attack_stat_route_without_changing_defense_overrides(
        string $attackType,
        ?string $explicitAttackStat,
    ): void {
        $attacker = $this->actor(luk: 100);
        $defender = $this->actor();
        $state = new BattleState($attacker, $defender, 'pvp');
        $skill = $this->skill($attackType);
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
        }

        $this->assertSame([
            'attack' => 125,
            'def' => 600,
            'spr' => 400,
        ], (new MiraclePvPRoomRule())->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            $attackType,
            DamageSourceType::JOB_ART,
            $skill,
            ['attack' => 1_800, 'def' => 600, 'spr' => 400],
        ));
    }

    public static function attackRouteProvider(): array
    {
        return [
            'physical STR route' => ['physical', null],
            'magical MAG route' => ['magical', null],
            'pure HYBRID route' => ['hybrid', null],
            'explicit STR on magical category' => ['magical', 'str'],
            'explicit MAG on physical category' => ['physical', 'mag'],
            'explicit AGI' => ['physical', 'agi'],
            'explicit DEF' => ['physical', 'def'],
            'explicit SPR' => ['magical', 'spr'],
            'explicit LUK still receives multiplier' => ['magical', 'luk'],
        ];
    }

    public function test_miracle_only_suppresses_luk_power_contribution_and_leaves_other_hooks_and_actor_stats_unchanged(): void
    {
        $attacker = $this->actor(str: 700, mag: 900, luk: 101);
        $defender = $this->actor();
        $state = new BattleState($attacker, $defender, 'pvp');
        $skill = $this->skill('physical');
        $skill->luk_power_rate = 0.5;
        $rule = new MiraclePvPRoomRule();
        $rawBefore = [$attacker->str, $attacker->mag, $attacker->luk];
        $effectiveBefore = [$attacker->effectiveStr(), $attacker->effectiveMag(), $attacker->effectiveLuk()];

        $rule->onBattleStart($attacker, $defender, $state);
        $this->assertFalse($rule->modifyInitiative($attacker, $defender, $state, 80, 100, false));
        $this->assertTrue($rule->modifyInitiative($attacker, $defender, $state, 80, 100, true));
        $this->assertSame(0, $rule->modifyLukPowerContribution($attacker, $defender, $state, $skill, 50));
        $this->assertSame(123, $rule->modifyFinalDamage(
            $attacker,
            $defender,
            $state,
            123,
            DamageSourceType::FIXED,
        ));
        $this->assertSame(123, $rule->modifyFinalDamage(
            $attacker,
            $defender,
            $state,
            123,
            DamageSourceType::PURE,
        ));
        $this->assertSame(77, $rule->modifyHealing($attacker, $defender, $state, 77));
        $rule->onActualHpLoss($attacker, $defender, $state, 55, DamageSourceType::JOB_ART);
        $rule->onActionEnd($attacker, $defender, $state);

        $this->assertSame(0.5, $skill->luk_power_rate);
        $this->assertSame($rawBefore, [$attacker->str, $attacker->mag, $attacker->luk]);
        $this->assertSame($effectiveBefore, [$attacker->effectiveStr(), $attacker->effectiveMag(), $attacker->effectiveLuk()]);
        $this->assertSame([], $state->logs);
    }

    public function test_null_and_existing_room_rules_preserve_luk_power_contribution(): void
    {
        $attacker = $this->actor(luk: 100);
        $defender = $this->actor();
        $state = new BattleState($attacker, $defender, 'pvp');
        $skill = $this->skill('physical');

        foreach ([
            new NullPvPRoomRule(),
            new DivineSpeedPvPRoomRule(),
            new ReverseTimePvPRoomRule(),
            new SealMagicPvPRoomRule(),
            new SealBladePvPRoomRule(),
        ] as $rule) {
            $this->assertSame(
                50,
                $rule->modifyLukPowerContribution($attacker, $defender, $state, $skill, 50),
                $rule::class,
            );
        }
    }

    private function skill(string $damageType): Skill
    {
        return new Skill([
            'skill_type' => 'job_art',
            'effect_template' => $damageType === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE',
            'damage_type' => $damageType,
        ]);
    }

    private function actor(int $str = 100, int $mag = 100, int $luk = 100): BattleActor
    {
        return new BattleActor('tester', true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => $str,
            'def' => 100,
            'agi' => 100,
            'mag' => $mag,
            'spr' => 100,
            'luk' => $luk,
        ]);
    }
}

final class PvPMiracleEffectiveLukActor extends BattleActor
{
    public function __construct(string $name, private readonly int $effectiveLukValue)
    {
        parent::__construct($name, true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }

    public function effectiveLuk(): int
    {
        return $this->effectiveLukValue;
    }
}
