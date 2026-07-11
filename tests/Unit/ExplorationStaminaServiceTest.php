<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\ExplorationStaminaService;
use App\Services\GameSettingService;
use App\Services\SupportPassService;
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

            public function getBool(string $key, bool $default = false): bool
            {
                return $default;
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
                'explore_stamina_max' => 250,
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

    public function test_max_for_character_adds_support_pass_bonus(): void
    {
        $this->app->instance(SupportPassService::class, new class extends SupportPassService
        {
            public function staminaBonusFor(Character $character): int
            {
                return 250;
            }
        });

        $service = new ExplorationStaminaService();
        $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
        $schemaReady->setValue($service, true);

        $character = new Character(['wins' => 0]);

        $this->assertSame(500, $service->maxForCharacter($character));
    }

    public function test_new_character_stamina_starts_at_250(): void
    {
        $service = new ExplorationStaminaService();
        $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
        $schemaReady->setValue($service, true);

        $character = new Character(['wins' => 0]);

        $this->assertSame(250, $service->maxForCharacter($character));
    }

    public function test_summary_normalizes_legacy_50_based_stamina_to_current_base_max(): void
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

            public function getBool(string $key, bool $default = false): bool
            {
                return $default;
            }
        });

        $service = new ExplorationStaminaService();
        $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
        $schemaReady->setValue($service, true);

        $character = new Character([
            'wins' => 0,
            'explore_stamina' => 50,
            'explore_stamina_max' => 50,
            'explore_stamina_updated_at' => CarbonImmutable::parse('2026-07-01 12:00:00'),
        ]);

        $summary = $service->summary($character);

        $this->assertSame(250, $summary['current']);
        $this->assertSame(250, $summary['max']);
    }

    public function test_summary_adds_max_increase_instead_of_refilling_to_full(): void
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

            public function getBool(string $key, bool $default = false): bool
            {
                return $default;
            }
        });

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 12:00:00'));

        try {
            $service = new ExplorationStaminaService();
            $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
            $schemaReady->setValue($service, true);

            // 787勝(baseMax=328)の状態で消費し、10勝残っていた(current=300)キャラが
            // 802勝(baseMax=330)まで進んでも、maxの増加分(+2)しか回復してはいけない。
            $character = new Character([
                'wins' => 802,
                'explore_stamina' => 300,
                'explore_stamina_max' => 328,
                'explore_stamina_updated_at' => CarbonImmutable::parse('2026-07-01 12:00:00'),
            ]);

            $summary = $service->summary($character);

            $this->assertSame(302, $summary['current']);
            $this->assertSame(330, $summary['max']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_stamina_reaches_500_at_3000_wins(): void
    {
        $service = new ExplorationStaminaService();
        $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
        $schemaReady->setValue($service, true);

        $this->assertSame(250, $service->maxForCharacter(new Character(['wins' => 0])));
        $this->assertSame(350, $service->maxForCharacter(new Character(['wins' => 1000])));
        $this->assertSame(450, $service->maxForCharacter(new Character(['wins' => 2000])));
        $this->assertSame(495, $service->maxForCharacter(new Character(['wins' => 2999])));
        $this->assertSame(500, $service->maxForCharacter(new Character(['wins' => 3000])));
        $this->assertSame(500, $service->maxForCharacter(new Character(['wins' => 10000])));
    }
}
