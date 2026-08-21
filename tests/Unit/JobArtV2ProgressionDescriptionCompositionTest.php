<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2ProgressionCatalog;
use PHPUnit\Framework\TestCase;

final class JobArtV2ProgressionDescriptionCompositionTest extends TestCase
{
    public function test_dark_contract_describes_its_cast_time_resource_gain_without_a_hit_condition(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;
        $skill = $this->art(30, 1, '闇の契約');

        $this->assertSame('c_design_eclipse_contract', $catalog->keyFor($skill));
        $this->assertSame(
            ['使用成立時に冥蝕+4・物理型/魔法型に応じた4ターン強化'],
            $catalog->effectTextsForDisplay($skill, true, true),
        );
    }

    public function test_spirit_steal_does_not_append_an_obsolete_effect_copy_to_its_canonical_description(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;
        $skill = $this->art(19, 5, 'スピリットスティール');

        $this->assertSame('c_design_eclipse_drain', $catalog->keyFor($skill));
        $this->assertSame([], $catalog->effectTextsForDisplay($skill, true, true));
    }

    public function test_resource_gain_and_cost_are_not_duplicated_as_progression_effect_texts(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;

        $producer = $this->art(57, 1, '金脈錬成');
        $this->assertSame('transmute_super_producer', $catalog->keyFor($producer));
        $this->assertSame([], $catalog->effectTextsForDisplay($producer, true));

        $finisher = $this->art(57, 9, '黄金創世陣');
        $this->assertSame('transmute_super_finisher', $catalog->keyFor($finisher));
        $this->assertSame([], $catalog->effectTextsForDisplay($finisher, true));
    }

    public function test_command_observation_keeps_its_unique_effect_without_repeating_resource_gain(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;
        $skill = $this->art(59, 1, '戦線把握');

        $this->assertSame('command_super_observe', $catalog->keyFor($skill));
        $this->assertSame(
            ['成功した戦技区分を記録し、次の指揮系譜戦技の発動率を+15ポイントする'],
            $catalog->effectTextsForDisplay($skill, true),
        );
    }

    public function test_break_mark_is_not_repeated_as_a_progression_effect_text(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;
        $skill = $this->art(68, 1, '雷冠練気');

        $this->assertSame('break_crown_mark', $catalog->keyFor($skill));
        $this->assertSame([], $catalog->effectTextsForDisplay($skill, true));
    }

    public function test_star_field_metadata_still_states_that_the_new_field_skips_its_own_attack(): void
    {
        $catalog = new JobArtV2ProgressionCatalog;
        $skill = $this->art(53, 1, '星読の瞬き');

        $this->assertSame('c_design_field_star_deploy', $catalog->keyFor($skill));
        $this->assertSame(
            ['星光の場を展開（生成した場はこの攻撃自身に適用しない）'],
            $catalog->effectTextsForDisplay($skill, true, true),
        );
    }

    private function art(int $jobId, int $rank, string $name): Skill
    {
        return new Skill([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
        ]);
    }
}
