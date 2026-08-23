<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\Skill;
use App\Services\Admin\SkillEffectPreviewService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SkillEffectPreviewServiceTest extends TestCase
{
    public function test_job_art_self_buff_changes_following_normal_damage(): void
    {
        $service = new SkillEffectPreviewService();
        $job = new JobClass(['name' => '戦士', 'normal_attack_type' => 'physical']);
        $enemy = new Enemy([
            'name' => '検証敵',
            'level' => 1,
            'max_hp' => 1000,
            'str' => 80,
            'def' => 100,
            'agi' => 50,
            'mag' => 50,
            'spr' => 60,
            'luk' => 10,
            'is_boss' => false,
        ]);
        $skill = new Skill([
            'name' => '挑発撃',
            'job_id' => 1,
            'learn_rank' => 1,
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_BUFF',
            'damage_type' => 'physical',
            'power' => 100,
            'hit_count' => 1,
            'inherit_on_master' => true,
            'inherited_rate' => 1.0,
            'activation_rate' => 24,
        ]);
        $skill->setRelation('jobClass', $job);

        $result = $service->preview($this->stats(), $job, $enemy, Collection::make([$skill]));

        $this->assertSame(50, $result['turns'][0]['damage']);
        $this->assertGreaterThan($result['turns'][0]['damage'], $result['turns'][2]['damage']);
        $this->assertContains('奥義テンプレートの自己バフ', $result['turns'][1]['effects']);
    }

    public function test_structured_enemy_def_down_is_reported_and_affects_following_damage(): void
    {
        $service = new SkillEffectPreviewService();
        $job = new JobClass(['name' => '剣士', 'normal_attack_type' => 'physical']);
        $enemy = new Enemy([
            'name' => '検証敵',
            'level' => 1,
            'max_hp' => 1000,
            'str' => 80,
            'def' => 100,
            'agi' => 50,
            'mag' => 50,
            'spr' => 60,
            'luk' => 10,
            'is_boss' => false,
        ]);
        $skill = new Skill([
            'name' => '防御崩し',
            'job_id' => 1,
            'learn_rank' => 1,
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_DEBUFF',
            'damage_type' => 'physical',
            'power' => 100,
            'hit_count' => 1,
            'enemy_def_down_percent' => 20,
            'inherit_on_master' => true,
            'inherited_rate' => 1.0,
            'activation_rate' => 24,
        ]);
        $skill->setRelation('jobClass', $job);

        $result = $service->preview($this->stats(), $job, $enemy, Collection::make([$skill]));

        $this->assertSame(50, $result['turns'][0]['damage']);
        $this->assertGreaterThan($result['turns'][0]['damage'], $result['turns'][2]['damage']);
        $this->assertContains('敵の防御20%低下', $result['turns'][1]['effects']);
    }

    public function test_job_art_heal_template_is_reported_without_heal_percent(): void
    {
        $service = new SkillEffectPreviewService();
        $job = new JobClass(['name' => '薬師', 'normal_attack_type' => 'physical']);
        $enemy = new Enemy([
            'name' => '検証敵',
            'level' => 1,
            'max_hp' => 1000,
            'str' => 80,
            'def' => 100,
            'agi' => 50,
            'mag' => 50,
            'spr' => 60,
            'luk' => 10,
            'is_boss' => false,
        ]);
        $skill = new Skill([
            'name' => '万能霊薬',
            'job_id' => 1,
            'learn_rank' => 1,
            'skill_type' => 'job_art',
            'effect_template' => 'HEAL_CLEANSE',
            'damage_type' => 'heal',
            'power' => 120,
            'hit_count' => 0,
            'heal_percent' => 0,
            'inherit_on_master' => true,
            'inherited_rate' => 0.7,
            'activation_rate' => 8,
        ]);
        $skill->setRelation('jobClass', $job);

        $result = $service->preview($this->stats(), $job, $enemy, Collection::make([$skill]));

        $this->assertSame(0, $result['turns'][1]['damage']);
        $this->assertContains('HP回復 84', $result['turns'][1]['effects']);
    }

    public function test_physical_drain_uses_atk_and_is_labeled_as_physical(): void
    {
        $service = new SkillEffectPreviewService();
        $job = new JobClass(['name' => '暗黒騎士', 'normal_attack_type' => 'physical']);
        $enemy = new Enemy([
            'name' => '検証敵',
            'level' => 1,
            'max_hp' => 10_000,
            'str' => 80,
            'def' => 100,
            'agi' => 50,
            'mag' => 50,
            'spr' => 60,
            'luk' => 10,
            'is_boss' => false,
        ]);
        $skill = new Skill([
            'name' => '暗黒剣',
            'job_id' => 1,
            'learn_rank' => 1,
            'skill_type' => 'job_art',
            'effect_template' => 'DRAIN',
            'damage_type' => 'physical',
            'power' => 185,
            'power_multiplier' => 1.85,
            'hit_count' => 1,
            'drain_hp_rate' => 0.35,
            'self_damage_percent' => 5,
            'inherit_on_master' => true,
            'inherited_rate' => 1.0,
            'activation_rate' => 14,
        ]);
        $skill->setRelation('jobClass', $job);

        $result = $service->preview($this->stats(), $job, $enemy, Collection::make([$skill]));

        $this->assertSame('物理', $result['skill_summaries'][0]['damage_type_label']);
        $this->assertSame(92, $result['skill_summaries'][0]['damage']);
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        return [
            'max_hp' => 1000,
            'max_mp' => 100,
            'str' => 100,
            'def' => 80,
            'agi' => 70,
            'mag' => 60,
            'spr' => 70,
            'luk' => 30,
        ];
    }
}
