<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

class JobArtV2SpCostCalculatorTest extends TestCase
{
    private JobArtV2SpCostCalculator $calculator;

    private JobArtV2BattleRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(JobArtV2SpCostCalculator::class);
        $this->rules = app(JobArtV2BattleRules::class);
    }

    public function test_normalized_sp_is_disabled_by_default(): void
    {
        $config = require base_path('config/battle.php');

        $this->assertFalse($config['job_art_v2']['normalized_sp']);
    }

    public function test_flag_dependencies_and_unsupported_jobs_fail_closed_to_legacy(): void
    {
        $skill = $this->art(1, 24, activationRate: 77, fixedSpCost: 10);

        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $this->assertSame(8, $this->calculator->forCurrentJob($skill, 400, 24, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 24));

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => false,
        ]);
        $this->assertSame(8, $this->calculator->forCurrentJob($skill, 400, 24, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 24));

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $this->assertSame(8, $this->calculator->forCurrentJob($skill, 400, 10, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 10));
    }

    public function test_frozen_costs_for_current_and_inherited_arts_across_sp_bands(): void
    {
        $this->enablePr5();
        $expected = [
            1 => [
                'current' => [100 => 2, 200 => 4, 300 => 6, 399 => 8, 400 => 8, 401 => 9, 800 => 15, 1600 => 28, 3200 => 53],
                'inherited' => [100 => 3, 200 => 5, 300 => 8, 399 => 10, 400 => 10, 401 => 11, 800 => 18, 1600 => 34, 3200 => 66],
            ],
            5 => [
                'current' => [100 => 4, 200 => 7, 300 => 10, 399 => 13, 400 => 13, 401 => 13, 800 => 24, 1600 => 44, 3200 => 86],
                'inherited' => [100 => 4, 200 => 8, 300 => 12, 399 => 16, 400 => 16, 401 => 17, 800 => 29, 1600 => 55, 3200 => 107],
            ],
            9 => [
                'current' => [100 => 5, 200 => 9, 300 => 14, 399 => 18, 400 => 18, 401 => 18, 800 => 32, 1600 => 61, 3200 => 119],
                'inherited' => [100 => 6, 200 => 11, 300 => 17, 399 => 22, 400 => 22, 401 => 23, 800 => 40, 1600 => 76, 3200 => 148],
            ],
        ];

        foreach ($expected as $rank => $origins) {
            foreach ($origins as $origin => $costs) {
                $skill = $this->art($rank, $origin === 'current' ? 24 : 99);
                $previous = 0;
                foreach ($costs as $maxSp => $expectedCost) {
                    $actual = $this->calculator->forCurrentJob($skill, $maxSp, 24, $origin);
                    $this->assertSame($expectedCost, $actual, "Rank{$rank} {$origin} maxSP{$maxSp}");
                    $this->assertGreaterThanOrEqual(1, $actual);
                    $this->assertGreaterThanOrEqual($previous, $actual);
                    $previous = $actual;
                }
            }
        }
    }

    public function test_sp_four_hundred_and_below_matches_pure_formula_and_current_never_exceeds_inherited(): void
    {
        $this->enablePr5();

        foreach ([1 => 50, 5 => 80, 9 => 110] as $rank => $pureRate) {
            foreach ([100, 200, 300, 399, 400] as $maxSp) {
                $current = $this->calculator->forCurrentJob($this->art($rank, 24), $maxSp, 24);
                $inherited = $this->calculator->forCurrentJob($this->art($rank, 99), $maxSp, 24);
                $this->assertSame((int) ceil(($maxSp * $pureRate) / 2500), $current);
                $this->assertSame((int) ceil(($maxSp * $pureRate) / 2000), $inherited);
                $this->assertLessThanOrEqual($inherited, $current);
            }
        }
    }

    public function test_source_job_id_controls_discount_and_unknown_source_is_inherited(): void
    {
        $this->enablePr5();

        $this->assertSame(8, $this->calculator->forCurrentJob($this->art(1, 24), 400, 24));
        $this->assertSame(10, $this->calculator->forCurrentJob($this->art(1, 53), 400, 24));

        $unknown = $this->art(1, 24);
        $unknown->setAttribute('job_id', null);
        $this->assertSame(10, $this->calculator->forCurrentJob($unknown, 400, 24, 'current'));
    }

    public function test_final_ceil_is_applied_only_after_min_and_current_job_discount(): void
    {
        $this->enablePr5();

        // Rank5 at maxSP401 has base numerator 32065. Final-only ceil is 13;
        // rounding the base to 17 first would incorrectly produce 14.
        $this->assertSame(13, $this->calculator->forCurrentJob($this->art(5, 24), 401, 24));
        $this->assertSame(17, $this->calculator->forCurrentJob($this->art(5, 99), 401, 24));
    }

    public function test_unknown_rank_keeps_legacy_cost_and_activation_rate(): void
    {
        $this->enablePr5();
        $skill = $this->art(3, 24, activationRate: 73, fixedSpCost: 10);

        $this->assertSame(8, $this->calculator->forCurrentJob($skill, 400, 24, 'current'));
        $this->assertSame(73, $this->rules->activationRateFor($skill, 24));
    }

    public function test_v2_activation_rates_do_not_mutate_master_values(): void
    {
        $this->enablePr5();

        foreach ([1 => 35, 5 => 38, 9 => 50] as $rank => $expectedRate) {
            $skill = $this->art($rank, 24, activationRate: 87);
            $this->assertSame($expectedRate, $this->rules->activationRateFor($skill, 24));
            $this->assertSame(87, $skill->effectiveActivationRate());
        }
    }

    public function test_inherited_art_uses_v2_activation_rate_without_mutating_master(): void
    {
        $this->enablePr5();
        $skill = $this->art(9, 53, activationRate: 87);
        $skill->setAttribute('job_art_origin', 'inherited');

        $this->assertSame(50, $this->rules->activationRateFor($skill, 24));
        $this->assertSame(50, $this->rules->activationRateFor($skill, 24, 'inherited'));
        $this->assertSame(50, $this->rules->activationRateFor($skill, 24, 'current'));
        $this->assertSame(87, $skill->effectiveActivationRate());
    }

    public function test_conserve_threshold_is_forty_percent_only_for_pr5_target(): void
    {
        $this->enablePr5();
        $target = $this->actor(24);
        $unsupported = $this->actor(10);

        $this->assertSame(0.40, $this->rules->conserveThresholdFor($target));
        $this->assertSame(0.60, $this->rules->conserveThresholdFor($unsupported));

        config(['battle.job_art_v2.normalized_sp' => false]);
        $this->assertSame(0.60, $this->rules->conserveThresholdFor($target));
    }

    private function enablePr5(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
    }

    private function art(
        int $rank,
        ?int $jobId,
        int $activationRate = 80,
        ?int $fixedSpCost = null,
    ): Skill {
        $skill = new Skill([
            'job_id' => $jobId,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'activation_rate' => $activationRate,
            'sp_cost_fixed' => $fixedSpCost,
            'effect_template' => 'DAMAGE',
        ]);
        $skill->setAttribute('id', 1000 + $rank);

        return $skill;
    }

    private function actor(int $currentJobId): BattleActor
    {
        return new BattleActor('actor', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'current_job_id' => $currentJobId,
        ]);
    }
}
