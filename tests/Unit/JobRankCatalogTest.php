<?php

namespace Tests\Unit;

use App\Support\JobRankCatalog;
use PHPUnit\Framework\TestCase;

class JobRankCatalogTest extends TestCase
{
    public function test_job_exp_multipliers_include_extended_ranks(): void
    {
        $this->assertSame(1.0, JobRankCatalog::jobExpMultiplier('normal'));
        $this->assertSame(1.0, JobRankCatalog::jobExpMultiplier('default'));
        $this->assertSame(2.0, JobRankCatalog::jobExpMultiplier('middle'));
        $this->assertSame(5.0, JobRankCatalog::jobExpMultiplier('advanced'));
        $this->assertSame(8.0, JobRankCatalog::jobExpMultiplier('super'));
        $this->assertSame(10.0, JobRankCatalog::jobExpMultiplier('crown'));
        $this->assertSame(15.0, JobRankCatalog::jobExpMultiplier('hero'));
        $this->assertSame(22.0, JobRankCatalog::jobExpMultiplier('legend'));
        $this->assertSame(30.0, JobRankCatalog::jobExpMultiplier('myth'));
    }

    public function test_inheritance_divisor_switches_from_advanced_to_super(): void
    {
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('normal'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('default'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('middle'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('advanced'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('super'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('crown'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('hero'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('legend'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('myth'));
    }
}
