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
            '自己強化 主+15% / 副+7%',
        ], $skill->jobArtNumericEffectLabels());
    }

    public function test_guard_labels_show_the_existing_fallback_reduction_value(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'GUARD_BARRIER',
            'power' => 185,
        ]);

        $this->assertSame(['被ダメージ -18%'], $skill->jobArtNumericEffectLabels());
    }
}
