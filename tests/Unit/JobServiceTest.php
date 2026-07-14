<?php

namespace Tests\Unit;

use App\Models\JobClass;
use App\Services\JobService;
use PHPUnit\Framework\TestCase;

class JobServiceTest extends TestCase
{
    public function test_hidden_super_jobs_are_released_for_conditional_display(): void
    {
        $service = new JobService();

        $this->assertTrue($service->isReleasedForJobChange(new JobClass([
            'rank' => 'super',
            'is_hidden' => true,
        ])));
    }

    public function test_hidden_crown_and_higher_jobs_are_not_released_without_a_character_proof(): void
    {
        $service = new JobService();

        foreach (['crown', 'hero', 'legend', 'myth'] as $rank) {
            $this->assertFalse($service->isReleasedForJobChange(new JobClass([
                'rank' => $rank,
                'is_hidden' => true,
            ])), $rank);
        }
    }

    public function test_visible_jobs_remain_released(): void
    {
        $service = new JobService();

        $this->assertTrue($service->isReleasedForJobChange(new JobClass([
            'rank' => 'advanced',
            'is_hidden' => false,
        ])));
    }

    public function test_high_rank_jobs_require_previous_high_rank_mastery(): void
    {
        $service = new JobService();

        $this->assertSame([], $service->prerequisiteMasterRanksFor(new JobClass(['rank' => 'super'])));
        $this->assertSame(['super'], $service->prerequisiteMasterRanksFor(new JobClass(['rank' => 'crown'])));
        $this->assertSame(['super', 'crown'], $service->prerequisiteMasterRanksFor(new JobClass(['rank' => 'hero'])));
        $this->assertSame(['super', 'crown', 'hero'], $service->prerequisiteMasterRanksFor(new JobClass(['rank' => 'legend'])));
        $this->assertSame(['legend'], $service->prerequisiteMasterRanksFor(new JobClass(['rank' => 'myth'])));
    }
}
