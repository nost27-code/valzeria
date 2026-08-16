<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Admin\SkillEffectPreviewService;
use App\Services\Battle\BattleActor;
use App\Services\EquipmentPermissionService;
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
    }

    #[DataProvider('normalAttackDamageTypeTemplates')]
    public function test_pvp_adaptive_templates_follow_the_attackers_normal_attack_type(string $template): void
    {
        $magicalAttacker = $this->actor('magical');
        $physicalAttacker = $this->actor('physical');

        $this->assertSame('magical', $this->resolve($magicalAttacker, $this->art($template, 'physical')));
        $this->assertSame('physical', $this->resolve($physicalAttacker, $this->art($template, 'magical')));
    }

    public function test_pvp_keeps_drain_hybrid_v2_and_other_explicit_routes(): void
    {
        $magicalAttacker = $this->actor('magical');
        $physicalAttacker = $this->actor('physical');

        $this->assertSame('physical', $this->resolve($magicalAttacker, $this->art('DRAIN', 'physical')));
        $this->assertSame('hybrid', $this->resolve($magicalAttacker, $this->art('HYBRID_DAMAGE', 'hybrid')));
        $this->assertSame('magical', $this->resolve($physicalAttacker, $this->art('MAGICAL_DAMAGE', 'magical')));

        $v2Explicit = $this->art('DAMAGE_BUFF', 'magical');
        $v2Explicit->setAttribute('job_art_v2_attack_stat', 'mag');
        $v2Explicit->setAttribute('job_art_v2_defense_stat', 'spr');

        $this->assertSame('magical', $this->resolve($physicalAttacker, $v2Explicit));
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

    private function art(string $template, string $damageType): Skill
    {
        return new Skill([
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $damageType,
        ]);
    }

    private function actor(string $normalAttackType): BattleActor
    {
        return new BattleActor('tester', true, [
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
        ]);
    }

    private function resolve(BattleActor $attacker, Skill $skill): string
    {
        $service = (new ReflectionClass(PvPBattleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PvPBattleService::class, 'resolveSkillDamageType');

        return $method->invoke($service, $attacker, $skill);
    }
}
