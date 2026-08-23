<?php

namespace Tests\Unit;

use App\Models\Skill;
use PHPUnit\Framework\TestCase;

class SkillNumericEffectLabelsTest extends TestCase
{
    public function test_damage_buff_labels_show_the_existing_power_tier_values(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_BUFF',
            'power' => 165,
            'hit_count' => 1,
        ]);

        $this->assertSame([
            '威力 165%',
            '自分の通常攻撃が物理なら攻撃を+15%、防御を+7%、魔法なら魔力を+15%、精神を+7%する',
        ], $skill->jobArtNumericEffectLabels());
    }

    public function test_magical_damage_buff_labels_name_magical_stats_directly(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'MAGICAL_DAMAGE_BUFF',
            'power' => 165,
            'hit_count' => 1,
        ]);

        $this->assertSame([
            '威力 165%',
            '自分の魔力を+15%、精神を+7%する',
        ], $skill->jobArtNumericEffectLabels());
    }

    public function test_guard_labels_show_the_existing_fallback_reduction_value(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'GUARD_BARRIER',
            'power' => 185,
        ]);

        $this->assertSame(
            ['次の自分の行動開始まで、受けるダメージを18%軽減する'],
            $skill->jobArtNumericEffectLabels(),
        );
    }

    public function test_damage_label_can_use_a_display_power_without_mutating_the_master(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'MAGICAL_DAMAGE',
            'power' => 320,
        ]);

        $this->assertSame(['威力 410%'], $skill->jobArtNumericEffectLabels(410));
        $this->assertSame(320, (int) $skill->power);
        $this->assertSame(['威力 320%'], $skill->jobArtNumericEffectLabels());
    }

    public function test_self_damage_label_shows_the_exact_max_hp_rate(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'PHYSICAL_DAMAGE',
            'power' => 165,
            'self_damage_percent' => 8,
        ]);

        $this->assertSame([
            '威力 165%',
            '反動：最大HP -8%',
        ], $skill->jobArtNumericEffectLabels());
    }

    public function test_structured_debuff_labels_include_the_master_duration(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_DEBUFF',
            'power' => 410,
            'duration_turns' => 3,
            'enemy_def_down_percent' => 20,
            'enemy_spr_down_percent' => 10,
        ]);

        $this->assertSame([
            '威力 410%',
            '敵防御 -20%（3ターン）',
            '敵精神 -10%（3ターン）',
        ], $skill->jobArtNumericEffectLabels());
    }
}
