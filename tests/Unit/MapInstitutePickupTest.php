<?php

namespace Tests\Unit;

use App\Livewire\MainScreen;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class MapInstitutePickupTest extends TestCase
{
    public function test_map_institute_pickup_is_available_until_the_configured_end_time(): void
    {
        Carbon::setTestNow('2026-07-22 12:00:00');

        try {
            $pickup = $this->invoke(new MainScreen(), 'mapInstitutePickup');

            $this->assertNotNull($pickup);
            $this->assertSame('地図院', $pickup['name']);
            $this->assertSame('exploration-maps.index', $pickup['route']);
            $this->assertArrayNotHasKey('ends_at_label', $pickup);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_map_institute_pickup_is_hidden_after_the_configured_end_time(): void
    {
        Carbon::setTestNow('2026-07-26 00:00:00');

        try {
            $this->assertNull($this->invoke(new MainScreen(), 'mapInstitutePickup'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function invoke(object $target, string $methodName): mixed
    {
        return (new ReflectionMethod($target, $methodName))->invoke($target);
    }
}
