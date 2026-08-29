<?php

namespace Tests\Unit;

use App\Http\Controllers\JobArtController;
use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2SpPowerScalingService;
use ReflectionMethod;
use Tests\TestCase;

class JobArtV2SpPowerScalingServiceTest extends TestCase
{
    private JobArtV2SpPowerScalingService $scaling;

    private JobArtV2SpCostCalculator $costs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScaling();
        $this->scaling = app(JobArtV2SpPowerScalingService::class);
        $this->costs = app(JobArtV2SpCostCalculator::class);
    }

    public function test_max_sp_ten_thousand_matches_all_twelve_variable_costs(): void
    {
        $expected = [
            1 => ['low' => 25, 'standard' => 75, 'high' => 150, 'max' => 250],
            5 => ['low' => 50, 'standard' => 150, 'high' => 300, 'max' => 500],
            9 => ['low' => 75, 'standard' => 225, 'high' => 450, 'max' => 750],
        ];

        foreach ($expected as $rank => $outputs) {
            foreach ($outputs as $output => $cost) {
                $this->assertSame(
                    $cost,
                    $this->scaling->variableCostFor(10_000, $rank, $output),
                    "Rank{$rank} {$output}",
                );
            }
        }
    }

    public function test_non_none_output_keeps_the_minimum_one_sp_boundary(): void
    {
        foreach ([1, 5, 9] as $rank) {
            foreach (['low', 'standard', 'high', 'max'] as $output) {
                $this->assertSame(1, $this->scaling->variableCostFor(0, $rank, $output));
            }
            $this->assertSame(0, $this->scaling->variableCostFor(0, $rank, 'none'));
        }
    }

    public function test_output_cost_is_strictly_increasing_and_efficiency_worsens_at_each_step(): void
    {
        $outputs = ['low', 'standard', 'high', 'max'];
        $bonusBps = [500, 1_000, 1_500, 2_000];

        foreach ([1, 5, 9] as $rank) {
            $costs = array_map(
                fn (string $output): int => $this->scaling->variableCostFor(10_000, $rank, $output),
                $outputs,
            );

            for ($index = 1; $index < count($costs); $index++) {
                $this->assertGreaterThan($costs[$index - 1], $costs[$index], "Rank{$rank} cost order");
                $this->assertGreaterThan(
                    $costs[$index - 1] * $bonusBps[$index],
                    $costs[$index] * $bonusBps[$index - 1],
                    "Rank{$rank} SP cost per 1% must worsen",
                );
            }
        }
    }

    public function test_power_curve_keeps_ten_thousand_values_diminishing_growth_and_thirty_percent_cap(): void
    {
        foreach (['none' => 0, 'low' => 500, 'standard' => 1_000, 'high' => 1_500, 'max' => 2_000] as $output => $expected) {
            $this->assertSame($expected, $this->scaling->bonusPartsFor(10_000, $output)['total'], $output);
        }

        $this->assertSame(2_000, $this->scaling->bonusPartsFor(10_000, 'max')['total']);
        $this->assertSame(2_001, $this->scaling->bonusPartsFor(10_050, 'max')['total']);
        $this->assertSame(3_000, $this->scaling->bonusPartsFor(60_000, 'max')['total']);
        $this->assertSame(3_000, $this->scaling->bonusPartsFor(100_000, 'max')['total']);
    }

    public function test_rank_five_max_cost_is_five_hundred_and_fixed_discount_does_not_touch_it(): void
    {
        $skill = $this->damageArt(rank: 5, jobId: 1);
        $result = $this->scaling->forReference(
            skill: $skill,
            currentJobId: 1,
            powerReference: 10_000,
            fixedCost: 6,
            outputKey: 'max',
            discountedFixedCost: 3,
        );

        $this->assertSame(500, $result->variableCost);
        $this->assertSame(3, $result->discountedFixedCost);
        $this->assertSame(503, $result->totalCost);

        $undiscounted = $this->scaling->forReference($skill, 1, 10_000, 6, 'max');
        $this->assertSame(500, $undiscounted->variableCost);
        $this->assertSame(506, $undiscounted->totalCost);
        $this->assertSame(2_000, $undiscounted->bonusBps);
    }

    public function test_non_persistent_output_budget_is_twenty_five_percent_and_never_auto_downgrades(): void
    {
        $actor = $this->actor('max', budget: 499);
        $skill = $this->damageArt(rank: 5, jobId: 1);

        $this->assertSame(2_500, $this->scaling->initialBudgetFor(10_000));
        $this->assertSame(500, $this->costs->scalingForActor($actor, $skill)->variableCost);
        $this->assertNull($this->costs->commitForActor($actor, $skill));
        $this->assertSame(499, $actor->spOutputBudgetRemaining());
        $this->assertSame(10_000, $actor->mp);
        $this->assertSame('max', $actor->jobArtStrategy['sp_output']);
        $this->assertSame(0, $actor->jobArtSpVariableSpent);
    }

    public function test_feature_flag_off_ignores_saved_output_for_cost_power_and_selection(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.enabled' => false]);
        $actor = $this->actor('max');
        $actor->mp = 6;
        $skill = $this->damageArt(rank: 5, jobId: 1);
        $skill->activation_rate = 100;
        $actor->jobArts = [$skill];

        $scaling = $this->costs->scalingForActor($actor, $skill);
        $this->assertSame(0, $scaling->variableCost);
        $this->assertSame(6, $scaling->totalCost);
        $this->assertSame(0, $scaling->bonusBps);
        $this->assertSame(20_000, $scaling->scaledPowerCenti(200));

        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.hit_resolution' => false,
            'battle.job_art_v2.damage_application' => false,
            'battle.job_art_v2.resources' => false,
            'battle.job_art_v2.rank5_v6' => false,
        ]);
        $enemy = new BattleActor('enemy', false, ['hp' => 100, 'max_hp' => 100]);
        $result = $this->selectionService()->selectForTurn($actor, new BattleState($actor, $enemy));

        $this->assertSame((int) $skill->id, $result->skill?->id);
        $this->assertTrue($result->activated);
    }

    public function test_champ_output_stays_fixed_only_until_the_dedicated_gate_is_enabled(): void
    {
        $actor = $this->actor('max', budget: 2_500);
        $actor->configureSpOutput(
            powerReference: 10_000,
            eligible: true,
            context: 'champ',
            budgetEnabled: true,
            initialBudget: 2_500,
        );
        $skill = $this->damageArt(rank: 5, jobId: 1);

        $disabled = $this->costs->scalingForActor($actor, $skill);
        $this->assertSame(0, $disabled->variableCost);
        $this->assertSame(6, $disabled->totalCost);
        $this->assertSame(0, $disabled->bonusBps);
        $this->assertSame(2_500, $actor->spOutputBudgetRemaining());

        config(['battle.job_art_v2.sp_power_scaling.champ_enabled' => true]);

        $enabled = $this->costs->scalingForActor($actor, $skill);
        $this->assertSame(500, $enabled->variableCost);
        $this->assertSame(506, $enabled->totalCost);
        $this->assertSame(2_000, $enabled->bonusBps);
        $this->assertSame(2_500, $actor->spOutputBudgetRemaining());
    }

    public function test_champ_uses_each_side_pvp_output_and_resets_only_the_budget_between_defenses(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.champ_enabled' => true]);

        $skill = $this->damageArt(rank: 5, jobId: 1);
        $challenger = $this->actor('low');
        $challenger->configureSpOutput(
            powerReference: 10_000,
            eligible: true,
            context: 'champ',
            budgetEnabled: true,
            initialBudget: 2_500,
        );
        $defender = $this->actor('max');
        $defender->configureSpOutput(
            powerReference: 10_000,
            eligible: true,
            context: 'champ',
            budgetEnabled: true,
            initialBudget: 2_500,
        );

        $challengerResult = $this->costs->commitForActor($challenger, $skill);
        $defenderResult = $this->costs->commitForActor($defender, $skill);

        $this->assertNotNull($challengerResult);
        $this->assertNotNull($defenderResult);
        $this->assertSame(50, $challengerResult->variableCost);
        $this->assertSame(500, $defenderResult->variableCost);
        $this->assertSame(2_450, $challenger->spOutputBudgetRemaining());
        $this->assertSame(2_000, $defender->spOutputBudgetRemaining());

        $defender->mp -= $defenderResult->totalCost;
        $persistedMp = $defender->mp;
        $nextDefense = $this->actor('max');
        $nextDefense->mp = $persistedMp;
        $nextDefense->configureSpOutput(
            powerReference: 10_000,
            eligible: true,
            context: 'champ',
            budgetEnabled: true,
            initialBudget: 2_500,
        );

        $this->assertSame(9_494, $nextDefense->mp);
        $this->assertSame(2_500, $nextDefense->spOutputBudgetRemaining());

        $nextDefenseResult = $this->costs->commitForActor($nextDefense, $skill);

        $this->assertNotNull($nextDefenseResult);
        $this->assertSame(500, $nextDefenseResult->variableCost);
        $this->assertSame(2_000, $nextDefense->spOutputBudgetRemaining());
    }

    public function test_non_damage_effects_receive_neither_variable_cost_nor_power_bonus(): void
    {
        foreach (['HEAL', 'HEAL_CLEANSE', 'GUARD_BARRIER', 'SELF_BUFF', 'ENEMY_DEBUFF', 'REWARD_MIXED'] as $template) {
            $skill = $this->art(rank: 5, jobId: 9_999, template: $template);
            $result = $this->scaling->forReference($skill, 1, 10_000, 6, 'max');

            $this->assertSame(0, $result->variableCost, $template);
            $this->assertSame(0, $result->bonusBps, $template);
            $this->assertSame(6, $result->totalCost, $template);
        }
    }

    public function test_zero_power_damage_template_keeps_fixed_cost_only(): void
    {
        $skill = $this->damageArt(rank: 9, jobId: 9_999);
        $skill->power = 0;
        $skill->power_multiplier = 0;

        $result = $this->scaling->forReference($skill, 1, 10_000, 50, 'max');

        $this->assertSame(0, $result->variableCost);
        $this->assertSame(50, $result->totalCost);
        $this->assertSame(0, $result->bonusBps);
        $this->assertFalse($result->powerScalingApplies);
    }

    public function test_reference_preview_uses_the_complete_runtime_gate_chain(): void
    {
        $skill = $this->damageArt(rank: 5, jobId: 1);
        config(['battle.job_art_v2.damage_application' => false]);

        $disabled = $this->scaling->forReference($skill, 1, 10_000, 6, 'max');
        $this->assertSame(0, $disabled->variableCost);

        config(['battle.job_art_v2.damage_application' => true]);
        config(['battle.job_art_v2.pvp_set' => false]);
        $pvpDisabled = $this->scaling->forReference(
            $skill,
            1,
            10_000,
            6,
            'max',
            context: 'pvp',
        );
        $this->assertSame(0, $pvpDisabled->variableCost);
    }

    public function test_reference_preview_fails_closed_for_an_unsupported_current_job(): void
    {
        $skill = $this->damageArt(rank: 5, jobId: 1);

        $unsupported = $this->scaling->forReference($skill, 9_999, 10_000, 6, 'max');

        $this->assertSame(0, $unsupported->variableCost);
        $this->assertSame(6, $unsupported->totalCost);
        $this->assertFalse($unsupported->powerScalingApplies);
    }

    public function test_failed_commit_clears_pending_scaling(): void
    {
        $actor = $this->actor('max');
        $skill = $this->damageArt(rank: 5, jobId: 1);
        $this->costs->prepareForActor($actor, $skill);
        $actor->mp = 0;

        $this->assertNull($this->costs->commitForActor($actor, $skill));
        $this->assertNull($actor->pendingJobArtSpScaling((int) $skill->id));
    }

    public function test_missing_budget_configuration_falls_back_to_twenty_five_percent(): void
    {
        $configuration = (array) config('battle.job_art_v2.sp_power_scaling', []);
        unset($configuration['output_budget_percent']);
        config(['battle.job_art_v2.sp_power_scaling' => $configuration]);

        $this->assertSame(2_500, $this->scaling->initialBudgetFor(10_000));
    }

    public function test_sp_recovery_and_hp_to_sp_conversion_damage_cards_are_excluded(): void
    {
        $spRecovery = $this->damageArt(rank: 5, jobId: 9_999);
        $spRecovery->mp_recover_percent = 5;
        $conversion = $this->damageArt(rank: 1, jobId: 8);

        foreach ([$spRecovery, $conversion] as $skill) {
            $result = $this->scaling->forReference($skill, 1, 10_000, 6, 'max');
            $this->assertSame(0, $result->variableCost);
            $this->assertSame(0, $result->bonusBps);
        }
    }

    public function test_secondary_effect_fields_are_not_scaled_on_hybrid_damage_arts(): void
    {
        foreach ([
            ['DAMAGE_BUFF', 'self_buff_percent', 13],
            ['DAMAGE_DEBUFF', 'enemy_def_down_percent', 17],
            ['DAMAGE_GUARD_BARRIER', 'damage_reduction_percent', 19],
            ['PHYSICAL_DAMAGE_REWARD', 'gold_bonus_percent', 7],
        ] as [$template, $field, $value]) {
            $actor = $this->actor('max');
            $skill = $this->damageArt(rank: 5, jobId: 1, template: $template);
            $skill->setAttribute($field, $value);

            $this->assertNotNull($this->costs->commitForActor($actor, $skill));
            $execution = app(JobArtBattleSupportService::class)->applyCommittedSpPower(
                $actor,
                $skill,
                clone $skill,
            );

            $this->assertSame(24_000, app(JobArtBattleSupportService::class)->actionPowerCenti($execution), $template);
            $this->assertSame($value, (int) $execution->getAttribute($field), $template);
        }
    }

    public function test_drain_damage_scales_but_recovery_base_excludes_sp_output_bonus(): void
    {
        $actor = $this->actor('max');
        $skill = $this->damageArt(rank: 5, jobId: 1, template: 'DRAIN');
        $skill->power = 200;
        $skill->power_multiplier = 2.0;

        $this->assertNotNull($this->costs->commitForActor($actor, $skill));
        $execution = app(JobArtBattleSupportService::class)->applyCommittedSpPower(
            $actor,
            $skill,
            clone $skill,
        );

        $this->assertSame(24_000, app(JobArtBattleSupportService::class)->actionPowerCenti($execution));
        $this->assertSame(
            1_000,
            app(JobArtBattleSupportService::class)->drainDamageBeforeSpOutput($execution, 1_200),
        );
    }

    public function test_ui_example_shows_variable_total_and_attack_power_for_rank_five_max(): void
    {
        $html = view('job-arts.partials.sp-output-settings', [
            'slotContext' => 'normal',
            'contextStrategies' => ['normal' => ['sp_output' => 'max']],
            'spOutputLabels' => ['max' => 'MAX'],
            'spOutputUiEnabled' => true,
            'spOutputPreviews' => [
                'max' => [
                    'eligible_count' => 1,
                    'variable_min' => 500,
                    'variable_max' => 500,
                    'total_min' => 506,
                    'total_max' => 506,
                    'bonus_bps' => 2_000,
                    'budget_initial' => null,
                ],
            ],
        ])->render();

        $this->assertStringContainsString('セット内の追加SP: 500', $html);
        $this->assertStringContainsString('セット内の合計SP: 506', $html);
        $this->assertStringContainsString('攻撃威力: +20%', $html);
        $this->assertStringContainsString('各戦技の合計消費SPは、下の戦技カードで確認できます', $html);
    }

    public function test_card_cost_matrix_keeps_exact_costs_for_eligible_and_excluded_arts(): void
    {
        $previews = [
            'none' => [
                'label' => 'なし',
                'rows' => [
                    ['skill_id' => 101, 'eligible' => true, 'fixed' => 6, 'variable' => 0, 'total' => 6],
                    ['skill_id' => 202, 'eligible' => false, 'fixed' => 36, 'variable' => 0, 'total' => 36],
                ],
            ],
            'max' => [
                'label' => 'MAX',
                'rows' => [
                    ['skill_id' => 101, 'eligible' => true, 'fixed' => 6, 'variable' => 500, 'total' => 506],
                    ['skill_id' => 202, 'eligible' => false, 'fixed' => 36, 'variable' => 0, 'total' => 36],
                ],
            ],
        ];

        $costs = (new ReflectionMethod(JobArtController::class, 'spOutputCardCosts'))
            ->invoke(new JobArtController, $previews);

        $this->assertSame([
            'label' => 'MAX',
            'eligible' => true,
            'fixed' => 6,
            'variable' => 500,
            'total' => 506,
        ], $costs[101]['max']);
        $this->assertSame([
            'label' => 'MAX',
            'eligible' => false,
            'fixed' => 36,
            'variable' => 0,
            'total' => 36,
        ], $costs[202]['max']);
    }

    public function test_card_cost_matrix_matches_all_five_outputs_for_each_rank_at_ten_thousand_sp(): void
    {
        $skills = collect([1, 5, 9])->map(function (int $rank): Skill {
            $skill = $this->damageArt(rank: $rank, jobId: 1);
            $skill->setAttribute('id', 100 + $rank);

            return $skill;
        });
        $labels = [
            'none' => 'なし',
            'low' => '低い',
            'standard' => '標準',
            'high' => '高い',
            'max' => 'MAX',
        ];
        $previews = (new ReflectionMethod(JobArtController::class, 'spOutputPreviews'))->invoke(
            new JobArtController,
            new Character(['current_job_id' => 1]),
            $skills,
            10_000,
            $labels,
            $this->costs,
            $this->scaling,
            'normal',
            false,
        );
        $costs = (new ReflectionMethod(JobArtController::class, 'spOutputCardCosts'))
            ->invoke(new JobArtController, $previews);

        $this->assertSame([4, 29, 79, 154, 254], array_column($costs[101], 'total'));
        $this->assertSame([6, 56, 156, 306, 506], array_column($costs[105], 'total'));
        $this->assertSame([8, 83, 233, 458, 758], array_column($costs[109], 'total'));
    }

    public function test_preview_rows_include_fixed_only_arts_without_polluting_output_ranges(): void
    {
        $damage = $this->damageArt(rank: 5, jobId: 1);
        $damage->setAttribute('id', 101);
        $support = $this->art(rank: 5, jobId: 1, template: 'HEAL');
        $support->setAttribute('id', 202);

        $previews = (new ReflectionMethod(JobArtController::class, 'spOutputPreviews'))->invoke(
            new JobArtController,
            new Character(['current_job_id' => 1]),
            collect([$damage, $support]),
            10_000,
            ['max' => 'MAX'],
            $this->costs,
            $this->scaling,
            'normal',
            false,
        );

        $this->assertSame(1, $previews['max']['eligible_count']);
        $this->assertSame(500, $previews['max']['variable_min']);
        $this->assertSame(500, $previews['max']['variable_max']);
        $this->assertCount(2, $previews['max']['rows']);
        $this->assertSame([
            'skill_id' => 202,
            'skill_name' => 'SP出力テスト Rank5',
            'rank' => 5,
            'eligible' => false,
            'fixed' => 6,
            'variable' => 0,
            'total' => 6,
            'bonus_bps' => 0,
        ], collect($previews['max']['rows'])->firstWhere('skill_id', 202));
    }

    public function test_equipped_art_cost_display_shows_selected_output_total_and_breakdown(): void
    {
        $costs = [
            'none' => ['label' => 'なし', 'eligible' => true, 'fixed' => 6, 'variable' => 0, 'total' => 6],
            'max' => ['label' => 'MAX', 'eligible' => true, 'fixed' => 6, 'variable' => 500, 'total' => 506],
        ];

        $html = view('job-arts.partials.sp-output-card-cost', [
            'artSpOutputCosts' => $costs,
            'selectedSpOutput' => 'max',
        ])->render();

        $this->assertStringContainsString('data-job-art-sp-output-cost-label>MAX</span>', $html);
        $this->assertStringContainsString('時の合計消費SP', $html);
        $this->assertStringContainsString('>506<', $html);
        $this->assertStringContainsString('固定 6 ＋ 追加 500', $html);
        $this->assertStringContainsString('data-job-art-sp-output-costs=', $html);
    }

    public function test_excluded_art_cost_display_stays_fixed_only(): void
    {
        $html = view('job-arts.partials.sp-output-card-cost', [
            'artSpOutputCosts' => [
                'max' => ['label' => 'MAX', 'eligible' => false, 'fixed' => 36, 'variable' => 0, 'total' => 36],
            ],
            'selectedSpOutput' => 'max',
        ])->render();

        $this->assertStringContainsString('data-job-art-sp-output-cost-label>MAX</span>', $html);
        $this->assertStringContainsString('時の合計消費SP', $html);
        $this->assertStringContainsString('>36<', $html);
        $this->assertStringContainsString('固定 36のみ（戦技出力対象外）', $html);
    }

    private function enableScaling(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.rank5_v6' => true,
            'battle.job_art_v2.sp_power_scaling.enabled' => true,
            'battle.job_art_v2.sp_power_scaling.champ_enabled' => false,
            'battle.job_art_v2.pvp_set' => true,
        ]);
    }

    private function actor(string $output, ?int $budget = null): BattleActor
    {
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 10_000,
            'max_mp' => 10_000,
            'current_job_id' => 1,
        ]);
        $actor->jobArtActivationPolicy = 'aggressive';
        $actor->jobArtStrategy = [
            'mode' => 'custom',
            'sp_policy' => 'aggressive',
            'sp_output' => $output,
            'settings' => ['sp_output' => $output],
        ];
        $actor->configureSpOutput(
            powerReference: 10_000,
            eligible: true,
            context: 'normal',
            budgetEnabled: $budget !== null,
            initialBudget: $budget ?? 0,
        );

        return $actor;
    }

    private function selectionService(): JobArtV2SelectionService
    {
        $random = new class extends JobArtV2RandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        };

        return new JobArtV2SelectionService(
            $random,
            app(\App\Services\JobArtV2FinisherConditionProvider::class),
            $this->costs,
            app(\App\Services\JobArtV2BattleRules::class),
        );
    }

    private function damageArt(int $rank, int $jobId, string $template = 'DAMAGE'): Skill
    {
        return $this->art($rank, $jobId, $template);
    }

    private function art(int $rank, int $jobId, string $template): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => "SP出力テスト Rank{$rank}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'effect_template' => $template,
            'power' => 200,
            'power_multiplier' => 2.0,
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', 90_000 + $jobId + $rank);

        return $skill;
    }
}
