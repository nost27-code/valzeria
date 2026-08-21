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

    public function test_fixed_sp_cost_does_not_depend_on_feature_flags_or_current_job(): void
    {
        $skill = $this->art(1, 24, activationRate: 77, fixedSpCost: 10);

        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $this->assertSame(6, $this->calculator->forCurrentJob($skill, 400, 24, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 24));

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => false,
        ]);
        $this->assertSame(6, $this->calculator->forCurrentJob($skill, 400, 24, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 24));

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $this->assertSame(6, $this->calculator->forCurrentJob($skill, 400, 39, 'current'));
        $this->assertSame(77, $this->rules->activationRateFor($skill, 39));

        $heroSkill = $this->art(1, 70, activationRate: 77, fixedSpCost: 10);
        $this->assertSame(30, $this->calculator->forCurrentJob($heroSkill, 800, 70, 'current'));
        $this->assertSame(50, $this->rules->activationRateFor($heroSkill, 70));
    }

    public function test_all_eight_job_tiers_use_the_fixed_rank_table(): void
    {
        $tiers = [
            1 => [1 => 4, 5 => 6, 9 => 8],
            9 => [1 => 6, 5 => 9, 9 => 13],
            27 => [1 => 10, 5 => 16, 9 => 22],
            50 => [1 => 16, 5 => 25, 9 => 35],
            60 => [1 => 23, 5 => 36, 9 => 50],
            70 => [1 => 30, 5 => 48, 9 => 66],
            80 => [1 => 40, 5 => 64, 9 => 88],
            85 => [1 => 52, 5 => 84, 9 => 115],
        ];

        foreach ($tiers as $jobId => $ranks) {
            foreach ($ranks as $rank => $expected) {
                foreach (['current', 'inherited'] as $origin) {
                    $this->assertSame(
                        $expected,
                        $this->calculator->forCurrentJob($this->art($rank, $jobId), 1, 24, $origin),
                        "job{$jobId} Rank{$rank} {$origin}",
                    );
                }
            }
        }
    }

    public function test_fixed_sp_cost_does_not_change_with_max_sp(): void
    {
        $this->enablePr5();

        foreach ([0, 1, 100, 400, 3_200, 99_999] as $maxSp) {
            foreach ([1 => 23, 5 => 36, 9 => 50] as $rank => $expected) {
                $this->assertSame($expected, $this->calculator->forCurrentJob($this->art($rank, 60), $maxSp, 1));
            }
        }
    }

    public function test_source_job_tier_controls_cost_but_current_job_and_origin_do_not(): void
    {
        $this->enablePr5();

        $skill = $this->art(1, 60);
        $this->assertSame(23, $this->calculator->forCurrentJob($skill, 400, 60, 'current'));
        $this->assertSame(23, $this->calculator->forCurrentJob($skill, 400, 1, 'inherited'));
    }

    public function test_legend_extension_jobs_share_the_same_legend_cost(): void
    {
        $this->enablePr5();

        $this->assertSame(40, $this->calculator->forCurrentJob($this->art(1, 95), 1, 1));
        $this->assertSame(64, $this->calculator->forCurrentJob($this->art(5, 99), 9_999, 1));
        $this->assertSame(88, $this->calculator->forCurrentJob($this->art(9, 99), 9_999, 1));
    }

    public function test_unknown_source_job_never_falls_back_to_legacy_sp_cost(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('job_id=39, learn_rank=1');

        $this->calculator->forCurrentJob($this->art(1, 39, fixedSpCost: 10), 400, 24, 'current');
    }

    public function test_unknown_rank_never_falls_back_to_legacy_sp_cost(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('job_id=24, learn_rank=3');

        $this->calculator->forCurrentJob($this->art(3, 24, activationRate: 73, fixedSpCost: 10), 400, 24, 'current');
    }

    public function test_v2_activation_rates_do_not_mutate_master_values(): void
    {
        $this->enablePr5();

        foreach ([1 => 50, 5 => 55, 9 => 60] as $rank => $expectedRate) {
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

        $this->assertSame(60, $this->rules->activationRateFor($skill, 24));
        $this->assertSame(60, $this->rules->activationRateFor($skill, 24, 'inherited'));
        $this->assertSame(60, $this->rules->activationRateFor($skill, 24, 'current'));
        $this->assertSame(87, $skill->effectiveActivationRate());
    }

    public function test_conserve_threshold_is_sixty_percent_for_all_jobs(): void
    {
        $this->enablePr5();
        $target = $this->actor(24);
        $hero = $this->actor(70);

        $this->assertSame(0.60, $this->rules->conserveThresholdFor($target));
        $this->assertSame(0.60, $this->rules->conserveThresholdFor($hero));
        $this->assertSame(60, $this->rules->conserveThresholdPercentForCurrentJob(24));

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
