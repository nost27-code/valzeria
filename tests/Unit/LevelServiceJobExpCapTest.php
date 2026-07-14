<?php

namespace Tests\Unit;

use App\Services\LevelService;
use PHPUnit\Framework\TestCase;

class LevelServiceJobExpCapTest extends TestCase
{
    public function test_job_exp_gain_is_capped_at_three(): void
    {
        $service = new LevelService();

        $this->assertSame(0, $service->capJobExpGain(0));
        $this->assertSame(3, $service->capJobExpGain(3));
        $this->assertSame(3, $service->capJobExpGain(4));
        $this->assertSame(3, $service->capJobExpGain(15));
        $this->assertSame(3, $service->capJobExpGain(25));
    }
}
