<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Services\DropService;
use App\Services\ExplorationMapGradeRewardService;
use App\Services\ExplorationMapLegacyRewardService;
use App\Services\ExplorationMapSeedService;
use Mockery;
use Tests\TestCase;

class ExplorationMapGradeRewardServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_hero_grade_can_grant_the_independent_material_bonus_slot(): void
    {
        config()->set('exploration_maps.grade_bonus_drop.rates_basis_points.hero', 10000);
        config()->set('exploration_maps.grade_bonus_drop.pool_weights', ['material' => 100]);

        $seed = Mockery::mock(ExplorationMapSeedService::class);
        $seed->shouldReceive('int')->once()->andReturn(1);
        $seed->shouldReceive('weightedPick')->once()->andReturn(['value' => 'material']);
        $legacy = Mockery::mock(ExplorationMapLegacyRewardService::class);
        $drops = Mockery::mock(DropService::class);
        $drops->shouldReceive('grantMapGradeMaterialBonus')
            ->once()
            ->andReturn(['material_id' => 123, 'quantity' => 1, 'kind' => 'material']);

        $service = new ExplorationMapGradeRewardService($seed, $legacy, $drops);
        $result = $service->tryDrop(
            new Character(),
            new ExplorationMap(['map_grade' => 'hero', 'generation_version' => 5]),
            new Enemy(),
            str_repeat('a', 64),
        );

        $this->assertSame([], $result['equipment']);
        $this->assertSame('map_grade_bonus', $result['materials'][0]['kind']);
        $this->assertSame(123, $result['materials'][0]['material_id']);
    }

    public function test_normal_grade_has_no_independent_bonus_slot(): void
    {
        $seed = Mockery::mock(ExplorationMapSeedService::class);
        $legacy = Mockery::mock(ExplorationMapLegacyRewardService::class);
        $drops = Mockery::mock(DropService::class);
        $service = new ExplorationMapGradeRewardService($seed, $legacy, $drops);

        $result = $service->tryDrop(
            new Character(),
            new ExplorationMap(['map_grade' => 'normal', 'generation_version' => 5]),
            new Enemy(),
            str_repeat('b', 64),
        );

        $this->assertSame(['materials' => [], 'equipment' => []], $result);
    }

    public function test_existing_generation_four_hero_map_does_not_gain_the_new_bonus_slot_retroactively(): void
    {
        $seed = Mockery::mock(ExplorationMapSeedService::class);
        $legacy = Mockery::mock(ExplorationMapLegacyRewardService::class);
        $drops = Mockery::mock(DropService::class);
        $service = new ExplorationMapGradeRewardService($seed, $legacy, $drops);

        $result = $service->tryDrop(
            new Character(),
            new ExplorationMap(['map_grade' => 'hero', 'generation_version' => 4]),
            new Enemy(),
            str_repeat('c', 64),
        );

        $this->assertSame(['materials' => [], 'equipment' => []], $result);
    }
}
