<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\MapExplorationRewardService;
use App\Services\NamelessRuinService;
use Tests\TestCase;

class NamelessRuinDepthExperienceTest extends TestCase
{
    public function test_every_zone_preserves_first_depth_and_increases_through_boss_changes(): void
    {
        $reference = $this->createMock(MapExplorationRewardService::class);
        $reference->method('normalReferenceFor')->willReturnCallback(static function (Enemy $enemy): array {
            // Include abrupt decreases as different-strength reference enemies are selected.
            $experience = (int) $enemy->max_hp % 3000 + 1000;
            return ['experience' => $experience, 'gold' => 10, 'job_experience' => 2, 'level' => 100, 'power' => 1];
        });
        $service = new NamelessRuinService($reference);
        foreach ($service->zones() as $zone) {
            foreach (array_slice($zone['enemies'], 0, 4) as $definition) {
                $this->assertIncreasing($service, $reference, $zone, $definition, false);
            }
            $this->assertIncreasing($service, $reference, $zone, $service->bossForDepth($zone, 1), true);
        }
    }

    private function assertIncreasing(NamelessRuinService $service, MapExplorationRewardService $reference, array $zone, array $definition, bool $boss): void
    {
        $previous = null;
        for ($depth = 1; $depth <= 100; $depth++) {
            $currentDefinition = $boss ? $service->bossForDepth($zone, $depth) : $definition;
            $base = $reference->normalReferenceFor(new Enemy($service->enemyStats($currentDefinition, $depth, $boss)))['experience'];
            $experience = $service->experienceForDepth($zone, $currentDefinition, $depth, $boss);
            $this->assertGreaterThanOrEqual($base, $experience);
            if ($previous === null) {
                $this->assertSame($base, $experience);
            } else {
                $this->assertGreaterThan($previous, $experience);
            }
            $previous = $experience;
        }
    }

    public function test_repeated_calls_reuse_the_curve_without_querying_references_again(): void
    {
        $reference = $this->createMock(MapExplorationRewardService::class);
        $reference->expects($this->exactly(100))->method('normalReferenceFor')->willReturn(['experience' => 1000]);
        $service = new NamelessRuinService($reference);
        $zone = $service->zones()['sand'];
        $definition = $zone['enemies'][0];
        $this->assertSame(1500, $service->experienceForDepth($zone, $definition, 100, false));
        $this->assertSame(1500, $service->experienceForDepth($zone, $definition, 100, false));
    }
}
