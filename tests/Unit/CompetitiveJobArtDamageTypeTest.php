<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\ArenaNpcRankingService;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
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

    public function test_vital_hit_is_logged_once_and_applied_to_every_hit_in_player_competitive_routes(): void
    {
        foreach (['pvp', 'champ'] as $route) {
            $result = $this->executeJobArt(
                $route,
                'MULTI_HIT',
                'physical',
                'physical',
                vitalHit: true,
                hitCount: 3,
            );

            $this->assertSame([true, true, true], $result['calculator']->criticalFlags, $route);
            $this->assertSame(1, substr_count($result['log'], '【急所命中！】'), $route);
        }
    }

    public function test_competitive_routes_carry_resolved_vital_hit_through_damage_and_usage_telemetry(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);

        foreach (['pvp', 'champ'] as $route) {
            $calculator = $this->recordingCalculator();
            $random = new class([99, 12]) extends JobArtV2HitRandomSource
            {
                private int $offset = 0;

                public function __construct(private readonly array $rolls)
                {
                }

                public function percentRoll(): int
                {
                    return $this->rolls[$this->offset++] ?? 100;
                }
            };
            $resolver = new ActionResolver(
                new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog),
                $calculator,
                $random,
                new JobArtV2ActiveEvasionProvider,
            );
            $skill = new Skill([
                'job_id' => 4,
                'learn_rank' => 1,
                'name' => '精密射撃',
                'skill_type' => 'job_art',
                'effect_template' => 'PHYSICAL_DAMAGE',
                'damage_type' => 'physical',
                'power' => 120,
                'power_multiplier' => 1.2,
                'hit_count' => 3,
            ]);
            $skill->setAttribute('id', 9_401);
            $sharedBuffDamageTypes = [];
            $support = $this->resolvedVitalSupport($skill, $resolver, $sharedBuffDamageTypes);
            $attacker = $this->actor('攻撃側', true, 'physical');
            $attacker->currentJobId = 4;
            $attacker->jobArts = [$skill];
            $attacker->jobArtRates[9_401] = 1.0;
            $defender = $this->actor('防御側', false, 'physical');
            $state = new BattleState($attacker, $defender, $route);

            if ($route === 'pvp') {
                $service = new class(
                    $this->createMock(CharacterStatusService::class),
                    $calculator,
                    $support,
                ) extends PvPBattleService
                {
                    public function runAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
                    {
                        $this->executeAction($attacker, $defender, $state, false);
                    }
                };
                $service->runAction($attacker, $defender, $state);
                $log = implode("\n", $state->logs);
            } else {
                $service = new ChampBattleService(
                    $this->createMock(CharacterStatusService::class),
                    $calculator,
                    $this->createMock(LevelService::class),
                    $support,
                );
                $action = $this->invoke($service, 'champAction', [$attacker, $defender, 100, 100, $state, false]);
                $log = (string) ($action['log'] ?? '');
            }

            $this->assertSame([true, true, true], $calculator->criticalFlags, $route);
            $this->assertSame(1, substr_count($log, '【急所命中！】'), $route);
            $this->assertSame(1, $state->jobArtUsageFor($attacker)[0]['activation_count'], $route);
            $this->assertSame(1, $state->jobArtUsageFor($attacker)[0]['hit_count'], $route);
            $this->assertSame(1, $state->jobArtUsageFor($attacker)[0]['vital_hit_count'], $route);
        }
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
        bool $vitalHit = false,
        int $hitCount = 1,
    ): array
    {
        $calculator = $this->recordingCalculator();

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
            'hit_count' => $hitCount,
        ]);
        $skill->setAttribute('id', 9_001);
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
        }
        $attacker->jobArtRates[9_001] = 1.0;

        $action = [];
        if ($route === 'pvp') {
            $service = new PvPBattleService($this->createMock(CharacterStatusService::class), $calculator, $support);
            $this->invoke($service, 'executeSkillAction', [$attacker, $defender, $state, $skill, false, null, $vitalHit]);
        } elseif ($route === 'champ') {
            $service = new ChampBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $this->createMock(LevelService::class),
                $support,
            );
            $action = $this->invoke($service, 'skillAttack', [$attacker, $defender, $skill, $state, null, null, $vitalHit]);
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

    private function recordingCalculator(): DamageCalculator
    {
        return new class extends DamageCalculator
        {
            /** @var list<string> */
            public array $attackTypes = [];
            /** @var list<bool> */
            public array $criticalFlags = [];

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
                ?int $skillPowerCenti = null,
            ): int {
                $this->attackTypes[] = $attackType;
                $this->criticalFlags[] = $isCritical;

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
                ?int $skillPowerCenti = null,
            ): int {
                $this->attackTypes[] = $attackType;
                $this->criticalFlags[] = $isCritical;

                return 40;
            }
        };
    }

    /** @param list<?string> $sharedBuffDamageTypes */
    private function resolvedVitalSupport(
        Skill $skill,
        ActionResolver $resolver,
        array &$sharedBuffDamageTypes,
    ): JobArtBattleSupportService {
        $support = $this->support($sharedBuffDamageTypes);
        $support->expects($this->once())
            ->method('beginAction')
            ->willReturnCallback(static fn (BattleActor $actor, BattleState $state): int => $state->beginSourceAction());
        $support->expects($this->once())->method('selectForTurn')->willReturn($skill);
        $support->expects($this->once())
            ->method('consumeAndMarkUse')
            ->willReturnCallback(static function (
                BattleActor $actor,
                BattleState $state,
                Skill $selectedSkill,
                ?string $activationLog = null,
            ): bool {
                $state->recordJobArtActivation($actor, $selectedSkill);

                return true;
            });
        $support->method('activationLog')->willReturn('精密射撃が発動！');
        $support->expects($this->once())->method('skillForExecution')->willReturn($skill);
        $support->expects($this->once())
            ->method('resolveHitWithDetails')
            ->willReturnCallback(static fn (
                BattleActor $attacker,
                BattleActor $defender,
                Skill $executionSkill,
                string $battleType,
                ?BattleState $state = null,
            ) => $resolver->resolveJobArtWithDetails($attacker, $defender, $executionSkill, $battleType, $state));
        $support->expects($this->once())
            ->method('completeJobArtCast')
            ->willReturnCallback(static function (
                BattleActor $actor,
                BattleState $state,
                Skill $selectedSkill,
                ?HitResult $hitResult = null,
                ?BattleActor $target = null,
                bool $vitalHit = false,
            ): void {
                $state->completeJobArtActivation($actor, $hitResult, $vitalHit);
            });

        return $support;
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
