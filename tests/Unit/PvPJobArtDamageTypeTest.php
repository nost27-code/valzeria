<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Admin\SkillEffectPreviewService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Services\EquipmentPermissionService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobCombatGuideService;
use App\Services\PvPBattleService;
use App\Support\JobArtEffectCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class PvPJobArtDamageTypeTest extends TestCase
{
    public function test_catalog_identifies_only_normal_attack_damage_type_templates(): void
    {
        foreach (self::normalAttackDamageTypeTemplates() as [$template]) {
            $this->assertTrue(JobArtEffectCatalog::usesNormalAttackDamageType($template), $template);
        }

        foreach (['PHYSICAL_DAMAGE', 'MAGICAL_DAMAGE', 'HYBRID_DAMAGE', 'DRAIN'] as $template) {
            $this->assertFalse(JobArtEffectCatalog::usesNormalAttackDamageType($template), $template);
        }
    }

    public function test_pvp_skill_execution_uses_the_shared_damage_type_resolver(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../app/Services/PvPBattleService.php');

        $this->assertStringContainsString(
            '$damageType = $this->resolveSkillDamageType($attacker, $skill);',
            $source,
        );
        $this->assertSame(
            1,
            substr_count($source, '$damageType = $this->resolveSkillDamageType($attacker, $skill);'),
            'executeSkillAction() must resolve the damage type exactly once.',
        );
    }

    #[DataProvider('normalAttackDamageTypeTemplates')]
    public function test_pvp_adaptive_templates_follow_the_attackers_normal_attack_type(string $template): void
    {
        $magicalAttacker = $this->actor('magical');
        $physicalAttacker = $this->actor('physical');

        $this->assertSame('magical', $this->resolve($magicalAttacker, $this->art($template, 'physical')));
        $this->assertSame('physical', $this->resolve($physicalAttacker, $this->art($template, 'magical')));

        $previewMethod = new ReflectionMethod(SkillEffectPreviewService::class, 'damageType');
        $this->assertSame(
            $this->resolve($magicalAttacker, $this->art($template, 'physical')),
            $previewMethod->invoke(new SkillEffectPreviewService(), $this->art($template, 'physical'), $magicalAttacker),
        );
        $this->assertSame(
            $this->resolve($physicalAttacker, $this->art($template, 'magical')),
            $previewMethod->invoke(new SkillEffectPreviewService(), $this->art($template, 'magical'), $physicalAttacker),
        );
    }

    #[DataProvider('adaptiveNormalAttackCases')]
    public function test_adaptive_jobs_keep_the_existing_mag_greater_than_str_boundary(
        int $str,
        int $mag,
        string $expectedDamageType,
    ): void {
        $attacker = $this->actor('adaptive', ['str' => $str, 'mag' => $mag]);
        $skill = $this->art('MULTI_HIT', $expectedDamageType === 'magical' ? 'physical' : 'magical');

        $this->assertSame($expectedDamageType, $this->resolve($attacker, $skill));
        $this->assertSame($expectedDamageType === 'magical', $attacker->usesMagForNormalAttack());
    }

    public function test_pvp_keeps_drain_hybrid_v2_and_other_explicit_routes(): void
    {
        $magicalAttacker = $this->actor('magical');
        $physicalAttacker = $this->actor('physical');

        $this->assertSame('physical', $this->resolve($magicalAttacker, $this->art('DRAIN', 'physical')));
        $this->assertSame('magical', $this->resolve($physicalAttacker, $this->art('DRAIN', 'magical')));
        $this->assertSame('hybrid', $this->resolve($magicalAttacker, $this->art('HYBRID_DAMAGE', 'hybrid')));
        $this->assertSame('magical', $this->resolve($physicalAttacker, $this->art('MAGICAL_DAMAGE', 'magical')));

        $v2Explicit = $this->art('DAMAGE_BUFF', 'magical');
        $v2Explicit->setAttribute('job_art_v2_attack_stat', 'mag');

        $this->assertSame('magical', $this->resolve($physicalAttacker, $v2Explicit));
    }

    #[DataProvider('damageBuffExecutionCases')]
    public function test_damage_buff_uses_the_selected_attack_stat_then_buffs_the_matching_stats(
        string $normalAttackType,
        array $attackerStats,
        string $expectedDamageType,
        int $expectedAttackPower,
        array $expectedStats,
        ?string $explicitAttackStat = null,
    ): void {
        $attacker = $this->actor($normalAttackType, array_merge([
            'hp' => 500,
            'max_hp' => 500,
        ], $attackerStats));
        $defender = $this->actor('physical', [
            'hp' => 500,
            'max_hp' => 500,
        ]);
        $state = new BattleState($attacker, $defender, 'pvp');
        $skill = $this->art('DAMAGE_BUFF', 'physical');
        $skill->id = 9001;
        $skill->name = '攻撃強化テスト';
        $skill->power = 200;
        $skill->power_multiplier = 2.0;
        $skill->hit_count = 1;
        if ($explicitAttackStat !== null) {
            $skill->setAttribute('job_art_v2_attack_stat', $explicitAttackStat);
            $skill->damage_type = $explicitAttackStat === 'mag' ? 'magical' : 'physical';
        }

        $calculator = new class extends DamageCalculator {
            /** @var list<array{damage_type:string, attack_power:int}> */
            public array $calls = [];

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
                $this->calls[] = [
                    'damage_type' => $attackType,
                    'attack_power' => $overrideAtk
                        ?? ($attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr()),
                ];

                return 40;
            }
        };

        $hpWhenBuffApplied = null;
        $support = $this->createMock(JobArtBattleSupportService::class);
        $support->method('isFieldOnlyArt')->willReturn(false);
        $support->method('defenseOverrides')->willReturn(['def' => null, 'spr' => null]);
        $support->method('damageStatOverrides')->willReturn(['attack' => null, 'def' => null, 'spr' => null, 'applied_ignore_rate' => 0.0]);
        $support->method('modifyFieldDamage')->willReturnArgument(2);
        $support->method('modifyJobArtDamage')->willReturnArgument(3);
        $support->method('usesDamageApplication')->willReturn(false);
        $support->method('applyTimedStructuredDebuffs')->willReturn(null);
        $support->expects($this->once())
            ->method('applySharedSelfBuff')
            ->willReturnCallback(function () use ($defender, &$hpWhenBuffApplied): null {
                $hpWhenBuffApplied = $defender->hp;

                return null;
            });

        $service = new class(
            new CharacterStatusService(),
            $calculator,
            $support,
            new DamageApplicationService(),
        ) extends PvPBattleService {
            public function executeForTest(
                BattleActor $attacker,
                BattleActor $defender,
                BattleState $state,
                Skill $skill,
            ): void {
                $this->executeSkillAction($attacker, $defender, $state, $skill, false);
            }
        };

        $service->executeForTest($attacker, $defender, $state, $skill);

        $this->assertSame([[
            'damage_type' => $expectedDamageType,
            'attack_power' => $expectedAttackPower,
        ]], $calculator->calls);
        $this->assertSame(460, $hpWhenBuffApplied, 'The shared buff must run after damage is applied.');
        $this->assertSame($expectedStats, [
            'str' => $attacker->str,
            'def' => $attacker->def,
            'mag' => $attacker->mag,
            'spr' => $attacker->spr,
        ]);
    }

    public function test_preview_and_job_guide_keep_explicit_routes_before_adaptive_templates(): void
    {
        $physicalAttacker = $this->actor('physical');
        $v2Explicit = $this->art('DAMAGE_BUFF', 'magical');
        $v2Explicit->setAttribute('job_art_v2_attack_stat', 'mag');
        $v2Explicit->setAttribute('job_art_v2_defense_stat', 'spr');

        $previewMethod = new ReflectionMethod(SkillEffectPreviewService::class, 'damageType');
        $this->assertSame(
            'magical',
            $previewMethod->invoke(new SkillEffectPreviewService(), $v2Explicit, $physicalAttacker),
        );

        $guide = new JobCombatGuideService(new EquipmentPermissionService());
        $this->assertSame('魔力参照', $guide->damageReference($v2Explicit, 'physical'));
        $this->assertSame('攻撃参照', $guide->damageReference($this->art('DRAIN', 'physical'), 'magical'));
    }

    /** @return list<array{string}> */
    public static function normalAttackDamageTypeTemplates(): array
    {
        return [
            ['MULTI_HIT'],
            ['DAMAGE_BUFF'],
            ['DAMAGE_DEBUFF'],
            ['DAMAGE_GUARD_BARRIER'],
        ];
    }

    /** @return list<array{int, int, string}> */
    public static function adaptiveNormalAttackCases(): array
    {
        return [
            'MAG > STR' => [100, 101, 'magical'],
            'MAG = STR' => [100, 100, 'physical'],
            'MAG < STR' => [101, 100, 'physical'],
        ];
    }

    /** @return array<string, array{string, array<string, int>, string, int, array<string, int>, ?string}> */
    public static function damageBuffExecutionCases(): array
    {
        return [
            'physical uses STR then buffs STR and DEF' => [
                'physical',
                ['str' => 180, 'def' => 80, 'mag' => 90, 'spr' => 70],
                'physical',
                180,
                ['str' => 198, 'def' => 84, 'mag' => 90, 'spr' => 70],
                null,
            ],
            'magical uses MAG then buffs MAG and SPR' => [
                'magical',
                ['str' => 90, 'def' => 70, 'mag' => 180, 'spr' => 80],
                'magical',
                180,
                ['str' => 90, 'def' => 70, 'mag' => 198, 'spr' => 84],
                null,
            ],
            'v2 explicit MAG overrides a physical normal attack' => [
                'physical',
                ['str' => 90, 'def' => 70, 'mag' => 180, 'spr' => 80],
                'magical',
                180,
                ['str' => 90, 'def' => 70, 'mag' => 198, 'spr' => 84],
                'mag',
            ],
        ];
    }

    private function art(string $template, string $damageType): Skill
    {
        return new Skill([
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $damageType,
        ]);
    }

    /** @param array<string, int|string> $overrides */
    private function actor(string $normalAttackType, array $overrides = []): BattleActor
    {
        return new BattleActor('tester', true, array_replace([
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'normal_attack_type' => $normalAttackType,
        ], $overrides));
    }

    private function resolve(BattleActor $attacker, Skill $skill): string
    {
        $service = (new ReflectionClass(PvPBattleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PvPBattleService::class, 'resolveSkillDamageType');

        return $method->invoke($service, $attacker, $skill);
    }
}
