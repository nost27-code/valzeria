<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\EquipmentPermissionService;
use App\Services\JobCombatGuideService;
use PHPUnit\Framework\TestCase;

class JobCombatGuideServiceTest extends TestCase
{
    private JobCombatGuideService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new JobCombatGuideService(new EquipmentPermissionService());
    }

    public function test_normal_attack_reference_uses_the_job_attack_type(): void
    {
        $this->assertSame('攻撃参照', $this->service->normalAttackReference('physical'));
        $this->assertSame('魔力参照', $this->service->normalAttackReference('magical'));
        $this->assertSame('攻撃・魔力の高い方', $this->service->normalAttackReference('adaptive'));
        $this->assertSame('攻撃参照', $this->service->normalAttackReference(null));
    }

    public function test_special_skill_reference_matches_the_battle_damage_branch(): void
    {
        $this->assertSame('攻撃参照', $this->service->damageReference(new Skill([
            'skill_type' => 'special',
            'damage_type' => 'drop',
            'power_multiplier' => 1.25,
        ])));
        $this->assertSame('魔力参照', $this->service->damageReference(new Skill([
            'skill_type' => 'special',
            'damage_type' => 'magical',
            'power_multiplier' => 1.55,
        ])));
        $this->assertSame('攻撃・魔力の平均', $this->service->damageReference(new Skill([
            'skill_type' => 'special',
            'damage_type' => 'hybrid',
            'hybrid_scaling' => 'average',
            'power_multiplier' => 1.75,
        ])));
        $this->assertSame('攻撃・魔力の高い方', $this->service->damageReference(new Skill([
            'skill_type' => 'special',
            'damage_type' => 'hybrid',
            'hybrid_scaling' => 'max',
            'power_multiplier' => 2.70,
        ])));
        $this->assertNull($this->service->damageReference(new Skill([
            'skill_type' => 'special',
            'damage_type' => 'heal',
            'power_multiplier' => 0,
        ])));
    }

    public function test_job_art_reference_supports_fixed_hybrid_and_job_dependent_damage(): void
    {
        $this->assertSame('攻撃参照', $this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'PHYSICAL_DAMAGE_REWARD',
        ]), 'magical'));
        $this->assertSame('魔力参照', $this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'MAGICAL_DAMAGE_REWARD',
        ]), 'physical'));
        $this->assertSame('攻撃・魔力の平均', $this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'HYBRID_DAMAGE',
            'hybrid_scaling' => 'average',
        ]), 'physical'));
        $this->assertSame('魔力参照', $this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'MULTI_HIT',
        ]), 'magical'));
        $this->assertSame('攻撃・魔力の高い方', $this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_BUFF',
        ]), 'adaptive'));
        $this->assertNull($this->service->damageReference(new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'REWARD_MIXED',
        ]), 'physical'));
    }
}
