<?php

namespace Tests\Unit;

use App\Services\ExplorationMapSeedService;
use Tests\TestCase;

class ExplorationMapSeedServiceTest extends TestCase
{
    public function test_context_randomness_is_stable_and_independent(): void
    {
        $service = app(ExplorationMapSeedService::class);
        $seed = str_repeat('a', 64);
        $this->assertSame($service->int($seed, 'map:v1:grade', 1, 10000), $service->int($seed, 'map:v1:grade', 1, 10000));
        $this->assertSame($service->explorationSeed($seed, 'encounter', 12, 99), $service->explorationSeed($seed, 'encounter', 12, 1));
        $this->assertNotSame($service->explorationSeed($seed, 'encounter', 12, 99), $service->explorationSeed($seed, 'reward', 12, 99));
    }
}
