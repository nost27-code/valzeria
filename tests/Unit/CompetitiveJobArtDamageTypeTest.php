<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\ArenaNpcRankingService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CompetitiveJobArtDamageTypeTest extends TestCase
{
    #[DataProvider('routeAndNormalAttackCases')]
    public function test_holy_sword_rending_follows_normal_attack_type_in_every_competitive_route(
        string $route,
        string $normalAttackType,
        string $configuredDamageType,
        string $expectedDamageType,
        string $expectedTextClass,
    ): void {
        $result = $this->executeDamageBuff($route, $normalAttackType, $configuredDamageType);

        $this->assertSame([$expectedDamageType], $result['calculator']->attackTypes, $route);
        $this->assertSame([$expectedDamageType], $result['sharedBuffDamageTypes'], $route);
        $this->assertStringContainsString($expectedTextClass, $result['log'], $route);
    }

    #[DataProvider('horizontalDamageTypeCases')]
    public function test_other_adaptive_and_explicit_routes_keep_damage_type_precedence(
        string $route,
        string $template,
        string $normalAttackType,
        string $configuredDamageType,
        string $expectedDamageType,
        string $expectedTextClass,
        ?string $explicitAttackStat = null,
    ): void {
        $result = $this->executeJobArt(
            $route,
            $template,
            $normalAttackType,
            $configuredDamageType,
            $explicitAttackStat,
        );

        $this->assertSame([$expectedDamageType], $result['calculator']->attackTypes, "{$route}:{$template}");
        $this->assertStringContainsString($expectedTextClass, $result['log'], "{$route}:{$template}");
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function routeAndNormalAttackCases(): array
    {
        $cases = [];
        foreach (['pvp', 'champ', 'arena_npc'] as $route) {
            $cases["{$route}:magical"] = [$route, 'magical', 'physical', 'magical', 'text-purple-600'];
            $cases["{$route}:physical"] = [$route, 'physical', 'magical', 'physical', 'text-red-600'];
        }

        return $cases;
    }

    /** @return array<string, array{string, string, string, string, string, string, ?string}> */
    public static function horizontalDamageTypeCases(): array
    {
        $cases = [];
        foreach (['pvp', 'champ', 'arena_npc'] as $route) {
            foreach (['MULTI_HIT', 'DAMAGE_DEBUFF', 'DAMAGE_GUARD_BARRIER'] as $template) {
                $cases["{$route}:{$template}:magical"] = [
                    $route, $template, 'magical', 'physical', 'magical', 'text-purple-600', null,
                ];
                $cases["{$route}:{$template}:physical"] = [
                    $route, $template, 'physical', 'magical', 'physical', 'text-red-600', null,
                ];
            }

            $cases["{$route}:DRAIN:physical"] = [
                $route, 'DRAIN', 'magical', 'physical', 'physical', 'text-red-600', null,
            ];
            $cases["{$route}:DRAIN:magical"] = [
                $route, 'DRAIN', 'physical', 'magical', 'magical', 'text-purple-600', null,
            ];
            $cases["{$route}:v2-explicit:magical"] = [
                $route, 'DAMAGE_BUFF', 'physical', 'magical', 'magical', 'text-purple-600', 'mag',
            ];
            $cases["{$route}:v2-explicit:physical"] = [
                $route, 'DAMAGE_BUFF', 'magical', 'physical', 'physical', 'text-red-600', 'str',
            ];
        }

        return $cases;
    }

    private function executeDamageBuff(string $route, string $normalAttackType, string $configuredDamageType): array
    {
        return $this->executeJobArt($route, 'DAMAGE_BUFF', $normalAttackType, $configuredDamageType);
    }

    private function executeJobArt(
        string $route,
        string $template,
        string $normalAttackType,
        string $configuredDamageType,
        ?string $explicitAttackStat = null,
    ): array
    {
        $calculator = new class extends DamageCalculator
        {
            /** @var list<string> */
            public array $attackTypes = [];

            public function calculateDuelDamage(
                BattleActor $attacker,
                BattleActor $defender,
                string $attackType,
                int $skillPower = 100,
                bool $isCritical = false,
                float $affinityMultiplier = 1.0,
                ?int $overrideAtk = null,
                ?int $overrideDef = null,
                ?int $overrideSpr = null,
            ): int {
                $this->attackTypes[] = $attackType;

                return 40;
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
                $this->attackTypes[] = $attackType;

                return 40;
            }
        };

        $sharedBuffDamageTypes = [];
        $support = $this->support($sharedBuffDamageTypes);
        $attacker = $this->actor('攻撃側', true, $normalAttackType);
        $defender = $this->actor('防御側', false, 'physical');
        $state = new BattleState($attacker, $defender, $route);
        $skill = new Skill([
            'name' => '聖剣烈破',
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $configuredDamageType,
            'power' => 110,
            'power_multiplier' => 1.1,
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', 9_001);
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
        }
        $attacker->jobArtRates[9_001] = 1.0;

        $action = [];
        if ($route === 'pvp') {
            $service = new PvPBattleService($this->createMock(CharacterStatusService::class), $calculator, $support);
            $this->invoke($service, 'executeSkillAction', [$attacker, $defender, $state, $skill, false, null]);
        } elseif ($route === 'champ') {
            $service = new ChampBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $this->createMock(LevelService::class),
                $support,
            );
            $action = $this->invoke($service, 'skillAttack', [$attacker, $defender, $skill, $state, null, null]);
        } else {
            $service = new ArenaNpcBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $this->createMock(ArenaNpcRankingService::class),
                $support,
            );
            $this->invoke($service, 'executeSkillAction', [$attacker, $defender, $state, $skill, false, null]);
        }

        return [
            'calculator' => $calculator,
            'sharedBuffDamageTypes' => $sharedBuffDamageTypes,
            'log' => $route === 'champ' ? (string) ($action['log'] ?? '') : implode("\n", $state->logs),
        ];
    }

    /** @param list<?string> $sharedBuffDamageTypes */
    private function support(array &$sharedBuffDamageTypes): JobArtBattleSupportService
    {
        $support = $this->createMock(JobArtBattleSupportService::class);
        $support->method('isFieldOnlyArt')->willReturn(false);
        $support->method('defenseOverrides')->willReturn(['def' => null, 'spr' => null]);
        $support->method('damageStatOverrides')->willReturn(['attack' => null, 'def' => null, 'spr' => null]);
        $support->method('modifyFieldDamage')->willReturnArgument(2);
        $support->method('modifyJobArtDamage')->willReturnArgument(3);
        $support->method('usesDamageApplication')->willReturn(false);
        $support->method('applyTimedStructuredDebuffs')->willReturn(null);
        $support->method('applySharedSelfBuff')->willReturnCallback(
            static function (
                BattleActor $actor,
                BattleState $state,
                Skill $skill,
                ?string $damageType = null,
            ) use (&$sharedBuffDamageTypes): null {
                $sharedBuffDamageTypes[] = $damageType;

                return null;
            },
        );

        return $support;
    }

    private function actor(string $name, bool $isPlayer, string $normalAttackType): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'normal_attack_type' => $normalAttackType,
        ]);
    }

    private function invoke(object $service, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($service, $method))->invokeArgs($service, $arguments);
    }
}
