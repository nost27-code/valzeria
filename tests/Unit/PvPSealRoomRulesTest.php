<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPAttackStatRouteResolver;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PvPSealRoomRulesTest extends TestCase
{
    #[DataProvider('resolvedRouteProvider')]
    public function test_resolver_uses_explicit_attack_stat_before_the_resolved_damage_route(
        string $resolvedAttackType,
        ?string $explicitAttackStat,
        string $template,
        string $expected,
    ): void {
        $skill = $this->skill($template, $resolvedAttackType, $explicitAttackStat);

        $this->assertSame(
            $expected,
            (new PvPAttackStatRouteResolver())->resolve($resolvedAttackType, $skill),
        );
    }

    public static function resolvedRouteProvider(): array
    {
        return [
            'normal physical' => ['physical', null, 'PHYSICAL_DAMAGE', PvPAttackStatRouteResolver::STR],
            'normal magical' => ['magical', null, 'MAGICAL_DAMAGE', PvPAttackStatRouteResolver::MAG],
            'v2 MAG overrides physical category' => ['physical', 'mag', 'PHYSICAL_DAMAGE', PvPAttackStatRouteResolver::MAG],
            'v2 STR overrides magical category' => ['magical', 'str', 'MAGICAL_DAMAGE', PvPAttackStatRouteResolver::STR],
            'v2 LUK is not STR or MAG' => ['physical', 'luk', 'PHYSICAL_DAMAGE', PvPAttackStatRouteResolver::OTHER],
            'v2 AGI is not STR or MAG' => ['magical', 'agi', 'MAGICAL_DAMAGE', PvPAttackStatRouteResolver::OTHER],
            'v2 DEF is not STR or MAG' => ['physical', 'def', 'PHYSICAL_DAMAGE', PvPAttackStatRouteResolver::OTHER],
            'v2 SPR is not STR or MAG' => ['magical', 'spr', 'MAGICAL_DAMAGE', PvPAttackStatRouteResolver::OTHER],
            'pure hybrid remains hybrid' => ['hybrid', null, 'HYBRID_DAMAGE', PvPAttackStatRouteResolver::HYBRID],
            'physical drain follows resolved physical route' => ['physical', null, 'DRAIN', PvPAttackStatRouteResolver::STR],
            'magical drain follows resolved magical route' => ['magical', null, 'DRAIN', PvPAttackStatRouteResolver::MAG],
        ];
    }

    #[DataProvider('adaptiveRouteProvider')]
    public function test_adaptive_route_keeps_the_existing_mag_strictly_greater_boundary(
        int $str,
        int $mag,
        string $expected,
    ): void {
        $attacker = $this->actor(str: $str, mag: $mag, normalAttackType: 'adaptive');
        $resolvedAttackType = $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';

        $this->assertSame(
            $expected,
            (new PvPAttackStatRouteResolver())->resolve($resolvedAttackType, null),
        );
    }

    public static function adaptiveRouteProvider(): array
    {
        return [
            'MAG greater than STR' => [100, 200, PvPAttackStatRouteResolver::MAG],
            'MAG equals STR' => [100, 100, PvPAttackStatRouteResolver::STR],
            'MAG lower than STR' => [200, 100, PvPAttackStatRouteResolver::STR],
        ];
    }

    #[DataProvider('sealRuleProvider')]
    public function test_seal_rules_only_zero_the_target_attack_route_and_preserve_defense_overrides(
        string $ruleClass,
        string $resolvedAttackType,
        ?string $explicitAttackStat,
        int $expectedAttack,
    ): void {
        $attacker = $this->actor(str: 700, mag: 900);
        $defender = $this->actor();
        $state = new BattleState($attacker, $defender, 'pvp');
        $incoming = ['attack' => 500, 'def' => 300, 'spr' => 200];
        $skill = $this->skill('PHYSICAL_DAMAGE', $resolvedAttackType, $explicitAttackStat);

        /** @var PvPRoomRuleInterface $rule */
        $rule = new $ruleClass();
        $this->assertSame([
            'attack' => $expectedAttack,
            'def' => 300,
            'spr' => 200,
        ], $rule->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            $resolvedAttackType,
            DamageSourceType::JOB_ART,
            $skill,
            $incoming,
        ));
    }

    public static function sealRuleProvider(): array
    {
        return [
            'seal magic blocks MAG' => [SealMagicPvPRoomRule::class, 'magical', null, 0],
            'seal magic keeps STR' => [SealMagicPvPRoomRule::class, 'physical', null, 500],
            'seal magic keeps pure hybrid' => [SealMagicPvPRoomRule::class, 'hybrid', null, 500],
            'seal magic keeps OTHER' => [SealMagicPvPRoomRule::class, 'physical', 'luk', 500],
            'seal blade blocks STR' => [SealBladePvPRoomRule::class, 'physical', null, 0],
            'seal blade keeps MAG' => [SealBladePvPRoomRule::class, 'magical', null, 500],
            'seal blade keeps pure hybrid' => [SealBladePvPRoomRule::class, 'hybrid', null, 500],
            'seal blade keeps OTHER' => [SealBladePvPRoomRule::class, 'magical', 'agi', 500],
            'seal magic blocks explicit MAG on physical category' => [SealMagicPvPRoomRule::class, 'physical', 'mag', 0],
            'seal blade keeps explicit MAG on physical category' => [SealBladePvPRoomRule::class, 'physical', 'mag', 500],
            'seal blade blocks explicit STR on magical category' => [SealBladePvPRoomRule::class, 'magical', 'str', 0],
            'seal magic keeps explicit STR on magical category' => [SealMagicPvPRoomRule::class, 'magical', 'str', 500],
            'seal magic blocks explicit MAG before hybrid fallback' => [SealMagicPvPRoomRule::class, 'hybrid', 'mag', 0],
            'seal blade blocks explicit STR before hybrid fallback' => [SealBladePvPRoomRule::class, 'hybrid', 'str', 0],
        ];
    }

    public function test_both_rules_leave_actor_stats_and_every_other_hook_unchanged(): void
    {
        foreach ([new SealMagicPvPRoomRule(), new SealBladePvPRoomRule()] as $rule) {
            $attacker = $this->actor(str: 700, mag: 900);
            $defender = $this->actor();
            $state = new BattleState($attacker, $defender, 'pvp');
            $rawBefore = [$attacker->str, $attacker->mag];
            $effectiveBefore = [$attacker->effectiveStr(), $attacker->effectiveMag()];

            $rule->onBattleStart($attacker, $defender, $state);
            $this->assertFalse($rule->modifyInitiative($attacker, $defender, $state, 80, 100, false));
            $this->assertTrue($rule->modifyInitiative($attacker, $defender, $state, 80, 100, true));
            $this->assertSame(50, $rule->modifyLukPowerContribution(
                $attacker,
                $defender,
                $state,
                new Skill(),
                50,
            ));
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

            $this->assertSame($rawBefore, [$attacker->str, $attacker->mag]);
            $this->assertSame($effectiveBefore, [$attacker->effectiveStr(), $attacker->effectiveMag()]);
            $this->assertSame([], $state->logs);
        }
    }

    private function skill(string $template, string $damageType, ?string $explicitAttackStat): Skill
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $damageType,
        ]);
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
        }

        return $skill;
    }

    private function actor(
        int $str = 100,
        int $mag = 100,
        string $normalAttackType = 'physical',
    ): BattleActor {
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
            'luk' => 100,
            'normal_attack_type' => $normalAttackType,
        ]);
    }
}
