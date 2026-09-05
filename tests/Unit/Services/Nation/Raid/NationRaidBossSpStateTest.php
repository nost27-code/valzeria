<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidBossSpState;
use PHPUnit\Framework\TestCase;

final class NationRaidBossSpStateTest extends TestCase
{
    public function test_defensive_reservation_failure_branch_does_not_consume_sp(): void
    {
        $state = new NationRaidBossSpState;
        $state->reduce(1, 90, 'test_setup');

        $this->assertFalse($state->reserve(5));
        $this->assertSame(10, $state->current());
        $this->assertSame(1, $state->reservationFailureCount());
    }

    public function test_recovery_slow_consumes_only_actual_recoveries(): void
    {
        $state = new NationRaidBossSpState;
        $this->assertTrue($state->reserve(17));
        $state->applyRecoverySlow(18, 2);
        $state->recordNoAction(18, 'delay');
        $this->assertSame(80, $state->current());
        $state->completedAction(19);
        $this->assertSame(84, $state->current());
        $state->completedAction(20);
        $this->assertSame(88, $state->current());
    }
}
