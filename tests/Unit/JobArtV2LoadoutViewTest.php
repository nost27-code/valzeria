<?php

namespace Tests\Unit;

use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\JobArtV2LineageGuideCatalog;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2SlotConditionCatalog;
use Tests\TestCase;

class JobArtV2LoadoutViewTest extends TestCase
{
    public function test_v2_slot_card_shows_role_lineage_cost_resource_and_priority_without_duplicate_stats_or_legacy_limits(): void
    {
        $skill = $this->skill(24, 9);
        $skill->setAttribute('job_art_effective_cost', 3);
        $skill->setAttribute('job_art_display_sp_cost', 18);
        $skill->setAttribute('job_art_icon_path', 'images/job_art/job_art_024_09.webp');
        $skill->setAttribute('job_art_v2_loadout_display', $this->allFlagsPresenter()->forArt(24, $skill));
        $slot = new CharacterJobArtSlot([
            'skill_id' => (int) $skill->id,
            'slot_no' => 3,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);

        $html = $this->renderSlot($slot, collect([$skill]), 3, true, 24);

        $this->assertStringContainsString('data-job-art-slot-index', $html);
        $this->assertMatchesRegularExpression('/data-job-art-slot-index[^>]*>3<\/span>/', $html);
        $this->assertStringContainsString('奥義', $html);
        $this->assertStringContainsString('場術系譜', $html);
        $this->assertStringContainsString('Cost 3', $html);
        $this->assertSame(1, substr_count($html, 'Cost 3'));
        $this->assertStringNotContainsString('SP 18', $html);
        $this->assertStringNotContainsString('発動 50%', $html);
        $this->assertStringNotContainsString('現在職・', $html);
        $this->assertStringNotContainsString('継承・', $html);
        $this->assertMatchesRegularExpression('/星印を.*data-job-art-effect-value="spend"[^>]*>-12<\/span>し/s', $html);
        $this->assertStringContainsString('星印が12ある場合、セット順より先にこの奥義の発動判定を行う', $html);
        $this->assertStringContainsString('data-job-art-drag-handle', $html);
        $this->assertStringContainsString('data-job-art-drag-label', $html);
        $this->assertStringContainsString('data-job-art-slot-icon', $html);
        $this->assertStringContainsString('images/job_art/job_art_024_09.webp', $html);
        $this->assertStringContainsString('移動中', $html);
        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertStringContainsString('grid grid-cols-2', $html);
        $this->assertStringNotContainsString('data-job-art-slot-accordion-toggle', $html);
        $this->assertStringNotContainsString('data-job-art-slot-expanded', $html);
        $this->assertStringContainsString('data-job-art-slot-summary', $html);
        $this->assertStringContainsString('data-job-art-compact-slot', $html);
        $this->assertStringNotContainsString('line-clamp-2', $html);
        $this->assertStringNotContainsString('line-clamp-3', $html);
        $this->assertStringContainsString('data-job-art-effect-value="spend"', $html);
        $this->assertStringNotContainsString('data-job-art-policy-radio', $html);
        $this->assertStringNotContainsString('SPが30%以上ある時だけ発動します', $html);
        $this->assertStringNotContainsString('1戦1回', $html);
        $this->assertStringNotContainsString('CT', $html);
    }

    public function test_effect_text_colors_explicit_gains_and_spends_without_rendering_html_from_copy(): void
    {
        $html = view('job-arts.partials.effect-text', [
            'text' => '崩しを+4し、崩しを-12する。<script>alert(1)</script>',
        ])->render();

        $this->assertStringContainsString('data-job-art-effect-value="gain"', $html);
        $this->assertStringContainsString('data-job-art-effect-value="spend"', $html);
        $this->assertStringContainsString('text-emerald-700', $html);
        $this->assertStringContainsString('text-rose-700', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_v2_empty_and_dormant_slots_remain_visible_without_horizontal_width_constraints(): void
    {
        $empty = $this->renderSlot(null, collect(), 4, true, 24);
        $this->assertMatchesRegularExpression('/data-job-art-slot-index[^>]*>4<\/span>/', $empty);
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
        $this->assertStringContainsString('連携', $dormant);
        $this->assertStringContainsString('休止中', $dormant);
        $this->assertStringContainsString('Cost上限超過', $dormant);
        $this->assertStringContainsString('min-w-0', $dormant);
        $this->assertStringNotContainsString('min-w-[', $dormant);
    }

    public function test_inherited_slot_shows_source_lineage_and_its_actual_resource_cost(): void
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
        $this->assertStringContainsString('照準系譜', $html);
        $this->assertStringNotContainsString('継承・照準', $html);
        $this->assertMatchesRegularExpression('/照準を.*data-job-art-effect-value="spend"[^>]*>-4<\/span>し/s', $html);
        $this->assertStringNotContainsString('崩しを', $html);
        $this->assertStringContainsString('min-w-0', $html);
        $this->assertStringNotContainsString('min-w-[', $html);
    }

    public function test_same_lineage_inherited_slot_shows_actual_resource_and_all_conditions_without_origin_or_duplicate_stats(): void
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

        $this->assertStringContainsString('場術系譜', $html);
        $this->assertStringNotContainsString('継承・同系譜', $html);
        $this->assertMatchesRegularExpression('/星印を.*data-job-art-effect-value="spend"[^>]*>-4<\/span>し/s', $html);
        $this->assertStringNotContainsString('SP 16', $html);
        $this->assertStringNotContainsString('発動 38%', $html);
        foreach (app(JobArtV2SlotConditionCatalog::class)->labels() as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
        $this->assertStringNotContainsString('発動条件', $html);
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
        $this->assertStringContainsString('data-job-art-policy-radio', $html);
        $this->assertStringNotContainsString('data-job-art-v2-details', $html);
        $this->assertStringNotContainsString('星印 +4（使用時）', $html);
    }

    public function test_page_template_keeps_legacy_and_v2_titles_tabs_cost_and_order_guidance_separate(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("\$pageTitle = \$jobArtV2UiEnabled ? '戦技セット' : '奥義セット'", $view);
        $this->assertStringContainsString("['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][\$slotContext]", $view);
        $this->assertStringContainsString('data-job-art-overview', $view);
        $this->assertStringContainsString('data-job-art-overview-meta', $view);
        $this->assertStringNotContainsString('data-job-art-current-lineage', $view);
        $this->assertStringNotContainsString('現在の系譜：', $view);
        $this->assertStringContainsString('data-job-art-overview-rules', $view);
        $this->assertStringContainsString('data-job-art-page-header', $view);
        $this->assertStringContainsString('data-job-art-context-summary', $view);
        $this->assertStringContainsString('data-job-art-compact-settings', $view);
        $this->assertStringContainsString('data-job-art-selected-list-heading', $view);
        $this->assertStringContainsString('<strong class="text-slate-700">発動順：</strong>上から / 奥義優先', $view);
        $this->assertStringContainsString('変更は自動保存されます', $view);
        $this->assertStringNotContainsString('class="shrink-0 rounded-lg border border-amber-200 bg-amber-50', $view);
        $this->assertStringNotContainsString('class="mt-3 rounded-lg border border-sky-100 bg-sky-50/80', $view);
        $this->assertStringNotContainsString('class="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/70', $view);
        $this->assertStringContainsString('Cost <strong class="text-slate-950"><span data-job-art-total-cost=', $view);
        $this->assertStringContainsString('@for($slotNo = 1; $slotNo <= $maxSlots; $slotNo++)', $view);
        $this->assertStringContainsString('$art->jobArtNumericEffectLabels(', $view);
        $this->assertStringContainsString("\$v2Display['effect_template'] ?? null", $view);
        $this->assertStringContainsString('@if(!$jobArtV2UiEnabled && $art->cooldown_turns)', $view);
        $this->assertStringContainsString('@if(!$jobArtV2UiEnabled && $art->max_uses_per_battle)', $view);
        $this->assertStringContainsString("(?:CT\\s*\\d+|1戦\\s*\\d+\\s*回)", $view);
        $this->assertStringContainsString('@unless($jobArtV2UiEnabled)', $view);
        $this->assertStringContainsString('data-job-art-resource-guide', $view);
        $this->assertStringContainsString('data-job-art-sortable="true"', $view);
        $this->assertStringContainsString("route('job-arts.reorder')", $view);
        $this->assertStringContainsString("root.addEventListener('pointerdown'", $view);
        $this->assertStringContainsString("root.addEventListener('dragstart'", $view);
        $this->assertStringContainsString('setSlotDragVisual', $view);
        $this->assertStringContainsString('data-job-art-drop-target', $view);
        $this->assertStringContainsString('どの職でもカードに書かれた威力と効果がすべて有効です', $view);
        $this->assertStringContainsString('現在職や系譜では増減しません', $view);
        $this->assertStringContainsString("'current' => '現在職'", $view);
        $this->assertStringContainsString("'inherited' => '継承'", $view);
        $this->assertStringContainsString('initializeSlotAccordions', $view);
        $this->assertStringContainsString("job-arts.partials.starter-presets", $view);
        $this->assertStringContainsString('$jobArtStarterPresetCount', $view);
        $this->assertStringNotContainsString('$jobArtStarterPresetsByContext', $view);
        $this->assertStringContainsString('data-job-art-active-lineages="{{ $slotContext }}"', $view);
        $this->assertStringContainsString("job-arts.partials.active-lineages", $view);
        $this->assertStringContainsString('replaceActiveLineages(context, payload.active_lineages_html)', $view);
        $this->assertStringContainsString('data-job-art-pvp-reward-note', $view);
        $this->assertStringContainsString('報酬系の戦技も設定できます', $view);
        $this->assertStringContainsString('報酬補正は対戦では発動しません', $view);
    }

    public function test_beginner_system_guide_opens_as_an_accessible_modal_with_exact_rules(): void
    {
        $page = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $html = view('job-arts.partials.system-guide', [
            'lineageGuides' => collect(app(JobArtV2LineageGuideCatalog::class)->all()),
            'maxSlots' => 5,
            'maxCost' => 9,
        ])->render();

        $this->assertIsString($page);
        $this->assertStringContainsString("job-arts.partials.system-guide", $page);
        $this->assertStringContainsString('data-job-art-system-guide-link', $html);
        $this->assertStringContainsString('戦技セットの解説を見る', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('data-job-art-system-guide-modal', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('5枠の順番と系譜リソース', $html);
        $this->assertStringContainsString('1 → 2 → 3 → 4 → 5 → 1…', $html);
        $this->assertStringContainsString('基礎発動率は始動50%・連携55%・奥義60%', $html);
        $this->assertStringContainsString('同じ手番で後ろの枠を再抽選しません', $html);
        $this->assertStringContainsString('候補優先とある効果は先に判定されます', $html);
        $this->assertSame(10, substr_count($html, 'data-job-art-system-guide-lineage='));
        $this->assertStringContainsString('「金冠錬符」はHIT時+4', $html);
        $this->assertStringContainsString('自傷分を足して+6にはなりません', $html);
        $this->assertStringContainsString('相手に次の1行動が渡る', $html);
        $this->assertStringContainsString('双方が敏捷の高い順に行動', $html);
        $this->assertStringContainsString('50ターン終了時', $html);
        $this->assertStringContainsString('最大体力に対する残り体力の割合を比べます', $html);
        $this->assertStringContainsString('挑戦者の割合が防衛者より高ければ挑戦者の判定勝利', $html);
        $this->assertStringContainsString('同じ割合または防衛者の方が高ければ防衛成功', $html);
        $this->assertStringContainsString('チャンプ戦だけは最大100ターン', $html);
        foreach (['ATK', 'DEF', 'MAG', 'SPR', 'SPD', 'LUK'] as $englishStatLabel) {
            $this->assertStringNotContainsString($englishStatLabel, $html);
        }
        $this->assertStringContainsString('最大5枠、合計Costは9まで', $html);
        $this->assertStringContainsString('奥義は1セットにつき1つまで', $html);
        $this->assertStringContainsString('同じ戦技を複数枠へ入れることもできません', $html);
    }

    public function test_active_lineage_summary_and_temporary_preset_highlight_are_player_facing(): void
    {
        $summary = view('job-arts.partials.active-lineages', [
            'activeLineages' => [[
                'lineage_key' => 'counter',
                'lineage_name' => '反撃',
                'resource_name' => '剣勢',
                'icon_path' => 'images/icon/icon_281.webp',
            ]],
        ])->render();
        $this->assertStringContainsString('有効な系譜', $summary);
        $this->assertStringContainsString('反撃系譜', $summary);
        $this->assertStringContainsString('剣勢', $summary);
        $this->assertStringContainsString('images/icon/icon_281.webp', $summary);

        $launcher = view('job-arts.partials.starter-presets', [
            'slotContext' => 'normal',
            'slotContextLabel' => '通常',
            'starterPresetCount' => 30,
            'starterPresetHighlighted' => true,
        ])->render();
        $this->assertStringContainsString('期間限定おすすめ', $launcher);
        $this->assertStringContainsString('公式プリセットから選ぶ', $launcher);
        $this->assertStringContainsString('（30件）', $launcher);
        $this->assertStringContainsString('bg-indigo-600', $launcher);

        $compactLauncher = view('job-arts.partials.starter-presets', [
            'slotContext' => 'normal',
            'slotContextLabel' => '通常',
            'starterPresetCount' => 30,
            'starterPresetHighlighted' => true,
            'compact' => true,
        ])->render();
        $this->assertStringContainsString('data-job-art-starter-preset-compact', $compactLauncher);
        $this->assertStringContainsString('期間限定おすすめ', $compactLauncher);
        $this->assertStringContainsString('30件', $compactLauncher);
        $this->assertStringNotContainsString('（30件）', $compactLauncher);
    }

    public function test_v2_page_filters_by_lineage_and_uses_one_effect_description(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("['counter', 'eclipse', 'pierce', 'hunt', 'aim', 'guard', 'transmute', 'break', 'command', 'field']", $view);
        $this->assertStringContainsString('@if($jobArtV2UiEnabled && $lineageTabs->isNotEmpty())', $view);
        $this->assertStringContainsString('data-job-art-lineage-filter="all"', $view);
        $this->assertStringContainsString('data-job-art-lineage-filter="{{ $lineageKey }}"', $view);
        $this->assertStringContainsString('data-job-art-lineage-guide="all"', $view);
        $this->assertStringContainsString('data-job-art-lineage-guide="{{ $lineageKey }}"', $view);
        $this->assertStringContainsString('10系譜の共通ルール', $view);
        $this->assertStringContainsString('{{ $guide[\'lineage_name\'] }}系譜の特性', $view);
        $this->assertStringContainsString('const lineageGuides = [...root.querySelectorAll(\'[data-job-art-lineage-guide]\')]', $view);
        $this->assertStringContainsString('guide.hidden = guide.dataset.jobArtLineageGuide !== currentLineage', $view);
        $this->assertStringContainsString('data-lineage-key="{{ $sourceLineageKey }}"', $view);
        $this->assertStringContainsString("const matchesLineage = currentLineage === 'all' || card.dataset.lineageKey === currentLineage", $view);
        $this->assertStringContainsString('const visible = matchesLineage && matchesFilters', $view);
        $this->assertStringContainsString('aria-label="系譜で戦技を絞り込む"', $view);
        $this->assertStringContainsString('class="mt-2 flex flex-wrap gap-1.5"', $view);
        $this->assertStringContainsString('data-job-art-v2-details', $view);
        $this->assertStringContainsString("\$v2Display['card_description']", $view);
        $this->assertStringNotContainsString("\$v2Display['resource_text']", $view);
        $this->assertStringNotContainsString("@foreach(\$v2Display['effect_texts']", $view);
        $this->assertStringContainsString('data-job-art-card-description', $view);
        $this->assertStringNotContainsString('data-job-art-origin-badge', $view);
        $this->assertStringContainsString("\$v2Display['card_description']", $view);
        $this->assertStringNotContainsString('data-job-art-card-role', $view);
        $this->assertStringNotContainsString('data-job-art-card-effect', $view);
        $this->assertStringNotContainsString('この戦技の要点', $view);
        $this->assertStringContainsString('PlayerStatLabel::inText', $view);
        $this->assertStringNotContainsString('$statLabelReplacements', $view);
    }

    public function test_v2_available_art_cards_use_one_card_hierarchy_for_fast_comparison(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-job-art-card-header', $view);
        $this->assertStringContainsString('data-job-art-card-header-row', $view);
        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)_auto]', $view);
        $this->assertStringNotContainsString('flex min-w-0 flex-col gap-2 min-[440px]:flex-row', $view);
        $this->assertStringContainsString('data-job-art-card-meta', $view);
        $this->assertStringContainsString('data-job-art-card-body', $view);
        $this->assertStringContainsString('data-job-art-card-description', $view);
        $this->assertStringContainsString('data-job-art-card-footer', $view);
        $this->assertStringNotContainsString('data-job-art-card-stats', $view);
        $this->assertStringContainsString('data-job-art-card-favorite', $view);
        $this->assertStringContainsString('data-job-art-card-icon', $view);
        $this->assertStringContainsString("asset(\$jobArtIconPath)", $view);
        $this->assertStringContainsString('data-job-art-lineage-icon', $view);
        $this->assertStringContainsString("asset(\$v2Display['source_lineage_icon_path'])", $view);
        $this->assertStringContainsString('{{ $lineageDisplayLabel }}のアイコン', $view);
        $this->assertStringContainsString('data-job-art-open-replace', $view);
        $this->assertMatchesRegularExpression('/>\s*セットする\s*<\/button>/u', $view);
        $this->assertStringContainsString("openReplaceBtn?.classList.remove('inline-flex')", $view);
        $this->assertStringContainsString("openReplaceBtn?.classList.add('hidden')", $view);
        $this->assertStringContainsString("target.skillName + 'と交換する'", $view);
        $this->assertStringContainsString('data-job-art-replace-modal', $view);
        $this->assertStringContainsString('data-job-art-replace-slots', $view);
        $this->assertStringContainsString('現在セット中の5枠です。入れ替える枠をタップしてください。', $view);
        $this->assertStringContainsString("const openReplacementModal = (button) =>", $view);
        $this->assertStringContainsString("await assignSkillToSlot(", $view);
        $this->assertStringContainsString("const POLICY_URL = @json(route('job-arts.policy'))", $view);
        $this->assertStringContainsString("'Accept': 'application/json'", $view);
        $this->assertStringContainsString('data-job-art-context-sp-policy-status', $view);
        $this->assertStringContainsString("1 => '始動'", $view);
        $this->assertStringContainsString("5 => '連携'", $view);
        $this->assertStringContainsString("9 => '奥義'", $view);
        $this->assertStringContainsString("{{ \$art->jobClass?->name ?? '職業' }} / {{ \$v2StageLabel }}", $view);
        $this->assertStringNotContainsString("{{ \$art->jobClass?->name ?? '職業' }} / Rank{{ \$art->learn_rank }}</div>\n                                        <div class=\"mt-0.5", $view);
        $this->assertStringContainsString('>効果</div>', $view);
        $this->assertStringContainsString("\$v2Display['display_description']", $view);
        $this->assertStringContainsString("\$v2Display['source_lineage_name'] . '系譜'", $view);
        $this->assertStringNotContainsString('data-job-art-origin-badge', $view);
        $this->assertStringContainsString('grid grid-cols-3', $view);
        $this->assertStringNotContainsString('sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.7fr)]', $view);
        $this->assertStringNotContainsString('<dt class="text-[9px] font-black tracking-wider text-slate-400">威力</dt>', $view);
        $this->assertStringNotContainsString('<dt class="text-[9px] font-black tracking-wider text-slate-400">発動率</dt>', $view);
        $this->assertStringNotContainsString('<dt class="text-[9px] font-black tracking-wider text-slate-400">SP</dt>', $view);
        $this->assertStringContainsString('data-job-art-loadout-diagnosis', $view);
        $this->assertStringContainsString('replaceDiagnosis(context, payload.diagnosis_html)', $view);
        $this->assertStringContainsString('data-job-art-favorite-icon', $view);
        $this->assertStringContainsString('<span class="sr-only">お気に入り</span>', $view);
        $this->assertStringNotContainsString('<span>お気に入り</span>', $view);
        $this->assertLessThan(
            strpos($view, 'data-job-art-card-meta'),
            strpos($view, 'data-job-art-card-favorite'),
        );
        $this->assertLessThan(
            strpos($view, 'aria-label="Cost {{ $cost }}"'),
            strpos($view, 'data-job-art-lineage-icon'),
        );
        $this->assertStringContainsString("'consumer' => 'border-sky-300", $view);
        $this->assertStringNotContainsString('data-job-art-card-width', $view);
    }

    public function test_v2_filters_follow_player_intent_instead_of_legacy_cost_and_time_groups(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("'available' => 'すべて'", $view);
        $this->assertStringContainsString("'equipped' => 'セット中'", $view);
        $this->assertStringContainsString("'starter' => '始動'", $view);
        $this->assertStringContainsString("'combo' => '連携'", $view);
        $this->assertStringContainsString("'ultimate' => '奥義'", $view);
        $this->assertStringContainsString("'buff' => '強化'", $view);
        $this->assertStringContainsString("'debuff' => '弱体'", $view);
        $this->assertStringContainsString("'recovery' => '回復'", $view);
        $this->assertStringContainsString("'defense' => '防御'", $view);
        $this->assertStringNotContainsString("'cost1' => 'Cost1'", $view);
        $this->assertStringNotContainsString("'time' => '時空'", $view);
        $this->assertStringContainsString("1 => 'starter'", $view);
        $this->assertStringContainsString("5 => 'combo'", $view);
        $this->assertStringContainsString("9 => 'ultimate'", $view);
        $this->assertStringContainsString("if (filter === 'equipped') return isEquipped;", $view);
        $this->assertStringContainsString("availableFilterKeys.has(requestedFilter) ? requestedFilter : 'available'", $view);
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
            'jobArtV2CardDetailsEnabled' => false,
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
