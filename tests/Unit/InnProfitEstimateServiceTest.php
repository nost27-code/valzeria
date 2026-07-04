<?php

namespace Tests\Unit;

use App\Services\InnProfitEstimateService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class InnProfitEstimateServiceTest extends TestCase
{
    public function test_period_estimate_includes_zero_revenue_days(): void
    {
        $service = new InnProfitEstimateService();

        $estimate = $service->periodEstimate(
            [
                '2026-07-01' => 50000,
                '2026-07-03' => 10000,
            ],
            Carbon::parse('2026-07-01')->startOfDay(),
            Carbon::parse('2026-07-03')->endOfDay()
        );

        $this->assertSame(60000, $estimate['revenue']);
        $this->assertSame(46800, $estimate['expense']);
        $this->assertSame(13200, $estimate['profit']);
    }
}
