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

    public function test_inheritance_divisor_keeps_legacy_whole_number_tiers(): void
    {
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('normal'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('default'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('middle'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('advanced'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('super'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('crown'));
        $this->assertSame(2, JobRankCatalog::inheritanceDivisor('hero'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('legend'));
        $this->assertSame(3, JobRankCatalog::inheritanceDivisor('myth'));
    }

    public function test_inheritance_rate_uses_three_balance_tiers(): void
    {
        $this->assertSame(0.5, JobRankCatalog::inheritanceRate('normal'));
        $this->assertSame(0.5, JobRankCatalog::inheritanceRate('default'));
        $this->assertSame(0.5, JobRankCatalog::inheritanceRate('middle'));
        $this->assertSame(0.5, JobRankCatalog::inheritanceRate('advanced'));
        $this->assertSame(0.4, JobRankCatalog::inheritanceRate('super'));
        $this->assertSame(0.4, JobRankCatalog::inheritanceRate('crown'));
        $this->assertSame(0.4, JobRankCatalog::inheritanceRate('hero'));
        $this->assertEqualsWithDelta(1 / 3, JobRankCatalog::inheritanceRate('legend'), 0.000001);
        $this->assertEqualsWithDelta(1 / 3, JobRankCatalog::inheritanceRate('myth'), 0.000001);
    }
}
