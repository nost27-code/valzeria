<?php

namespace Tests\Unit;

use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2SlotConditionCatalog;
use Tests\TestCase;

class JobArtV2LoadoutViewTest extends TestCase
{
    public function test_v2_slot_card_shows_role_origin_cost_sp_resource_priority_and_once_per_battle(): void
    {
        $skill = $this->skill(24, 9);
        $skill->setAttribute('job_art_effective_cost', 3);
        $skill->setAttribute('job_art_display_sp_cost', 18);
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(24, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 3,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);

        $html = $this->renderSlot($slot, collect([$skill]), 3, true, 24);

        $this->assertStringContainsString('[3]', $html);
        $this->assertStringContainsString('奥義', $html);
        $this->assertStringContainsString('現在職', $html);
        $this->assertStringContainsString('Cost 3', $html);
        $this->assertStringContainsString('SP 18', $html);
        $this->assertStringContainsString('星印 12消費', $html);
        $this->assertStringContainsString('条件成立時は最優先候補', $html);
        $this->assertStringContainsString('1戦1回', $html);
    }

    public function test_v2_empty_and_dormant_slots_remain_visible_without_horizontal_width_constraints(): void
    {
        $empty = $this->renderSlot(null, collect(), 4, true, 24);
        $this->assertStringContainsString('[4]', $empty);
        $this->assertStringContainsString('空き', $empty);
        $this->assertStringContainsString('戦技を設定', $empty);

        $skill = $this->skill(24, 5);
        $skill->setAttribute('job_art_effective_cost', 2);
        $skill->setAttribute('job_art_display_sp_cost', 13);
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(24, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 5,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);
        $slot->setAttribute('job_art_inactive_reason', 'cost_limit');
        $dormant = $this->renderSlot($slot, collect([$skill]), 5, true, 24);
        $this->assertStringContainsString('休止中', $dormant);
        $this->assertStringContainsString('Cost上限超過', $dormant);
        $this->assertStringContainsString('min-w-0', $dormant);
        $this->assertStringNotContainsString('min-w-[', $dormant);
    }

    public function test_inherited_slot_shows_source_lineage_without_foreign_resource_copy(): void
    {
        $skill = $this->skill(65, 5);
        $skill->setAttribute('job_art_origin', 'inherited');
        $skill->setAttribute('job_art_effective_cost', 2);
        $skill->setAttribute('job_art_display_sp_cost', 16);
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(68, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 4,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);

        $html = $this->renderSlot($slot, collect([$skill]), 4, true, 68);

        $this->assertStringContainsString('data-job-art-lineage-badge', $html);
        $this->assertStringContainsString('継承・照準', $html);
        $this->assertStringNotContainsString('照準 4消費', $html);
        $this->assertStringContainsString('min-w-0', $html);
        $this->assertStringNotContainsString('min-w-[', $html);
    }

    public function test_same_lineage_inherited_slot_shows_actual_resource_activation_sp_and_all_conditions(): void
    {
        $skill = $this->skill(24, 5);
        $skill->setAttribute('job_art_origin', 'inherited');
        $skill->setAttribute('job_art_effective_cost', 2);
        $skill->setAttribute('job_art_display_sp_cost', 16);
        $skill->setAttribute('job_art_display_activation_rate', 38);
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(53, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 4,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);
        $slot->setAttribute('job_art_slot_condition', 'main_resource_ge_4');

        $html = $this->renderSlot($slot, collect([$skill]), 4, true, 53);

        $this->assertStringContainsString('継承・同系譜', $html);
        $this->assertStringContainsString('星印 4消費', $html);
        $this->assertStringContainsString('SP 16', $html);
        $this->assertStringContainsString('発動 38%', $html);
        foreach (app(JobArtV2SlotConditionCatalog::class)->labels() as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('条件も戦技と一緒にプリセットへ保存されます', $html);
    }

    public function test_legacy_slot_card_keeps_existing_terms_and_hides_v2_metadata(): void
    {
        $skill = $this->skill(24, 1);
        $skill->setAttribute('job_art_origin', 'current');
        $skill->setAttribute('job_art_effective_cost', 1);
        $skill->setAttribute('job_art_display_sp_cost', 20);
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(24, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 1,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);

        $html = $this->renderSlot($slot, collect([$skill]), 1, false, 24, 5);

        $this->assertStringContainsString('SLOT 1', $html);
        $this->assertStringContainsString('本職', $html);
        $this->assertStringContainsString('Rank1 · SP20', $html);
        $this->assertStringNotContainsString('data-job-art-v2-details', $html);
        $this->assertStringNotContainsString('星印 +4', $html);
    }

    public function test_page_template_keeps_legacy_and_v2_titles_tabs_cost_and_order_guidance_separate(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("\$pageTitle = \$jobArtV2UiEnabled ? '戦技セット' : '奥義セット'", $view);
        $this->assertStringContainsString("\$jobArtV2UiEnabled ? (['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP']", $view);
        $this->assertStringContainsString('戦技は上から順に発動候補を判定します。', $view);
        $this->assertStringContainsString('条件を満たした奥義は優先されます。', $view);
        $this->assertStringContainsString('Cost <span data-job-art-total-cost=', $view);
        $this->assertStringContainsString('@for($slotNo = 1; $slotNo <= $maxSlots; $slotNo++)', $view);
        $this->assertStringContainsString('$art->jobArtNumericEffectLabels(', $view);
        $this->assertStringContainsString("\$v2Display['effect_template'] ?? null", $view);
    }

    private function allFlagsPresenter(): JobArtV2LoadoutPresenter
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);

        return app(JobArtV2LoadoutPresenter::class);
    }

    private function renderSlot(
        ?CharacterJobArtSlot $slot,
        $arts,
        int $slotNo,
        bool $jobArtV2UiEnabled,
        int $currentJobId,
        int $maxCost = 9,
    ): string {
        return view('job-arts.partials.slot-card', [
            'slotContext' => 'normal',
            'slotNo' => $slotNo,
            'slot' => $slot,
            'contextArts' => $arts,
            'allAvailableArts' => $arts,
            'maxSp' => 400,
            'activationPolicyLabels' => ['aggressive' => '積極', 'normal' => '通常', 'conserve' => '温存'],
            'activationPolicyDescriptions' => ['normal' => 'SPが30%以上ある時だけ発動します'],
            'contextTotalCost' => $slot ? 3 : 0,
            'maxCost' => $maxCost,
            'currentJobId' => $currentJobId,
            'jobArtV2UiEnabled' => $jobArtV2UiEnabled,
            'slotConditionLabels' => app(JobArtV2SlotConditionCatalog::class)->labels(),
        ])->render();
    }

    private function skill(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => "表示試験 {$jobId}-{$rank}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'art_cost' => 1,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        $skill->setAttribute('job_art_origin', 'current');
        $skill->setAttribute('job_art_rate', 1.0);
        $skill->setRelation('jobClass', null);

        return $skill;
    }
}
