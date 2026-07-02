<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\ExplorationStaminaService;
use App\Services\GameSettingService;
use Carbon\CarbonImmutable;
use ReflectionProperty;
use Tests\TestCase;

class ExplorationStaminaServiceTest extends TestCase
{
    public function test_summary_calculates_recovery_without_mutating_character(): void
    {
        $this->app->instance(GameSettingService::class, new class
        {
            public function getString(string $key, string $default = ''): string
            {
                return $key === 'exploration.mode' ? ExplorationStaminaService::MODE_STAMINA : $default;
            }

            public function getInt(string $key, int $default = 0): int
            {
                return match ($key) {
                    'exploration.stamina_recovery_seconds' => 60,
                    'exploration.stamina_cost' => 1,
                    default => $default,
                };
            }
        });

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 12:02:05'));

        try {
            $service = new ExplorationStaminaService();
            $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
            $schemaReady->setValue($service, true);

            $updatedAt = CarbonImmutable::parse('2026-07-01 12:00:00');
            $character = new Character([
                'wins' => 0,
                'explore_stamina' => 10,
                'explore_stamina_max' => 50,
                'explore_stamina_updated_at' => $updatedAt,
            ]);

            $summary = $service->summary($character);

            $this->assertSame(12, $summary['current']);
            $this->assertSame(10, $character->explore_stamina);
            $this->assertTrue($updatedAt->equalTo($character->explore_stamina_updated_at));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
