<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use App\Services\ExplorationMapDifficultyService;
use App\Services\MapPublicationService;
use Tests\TestCase;

class ExplorationMapDifficultyServiceTest extends TestCase
{
    public function test_each_grade_has_a_clear_enemy_level_band(): void
    {
        $service = app(ExplorationMapDifficultyService::class);

        $this->assertSame(['min' => 0, 'max' => 5], $service->levelOffsetRange('normal'));
        $this->assertSame(['min' => 8, 'max' => 15], $service->levelOffsetRange('rare'));
        $this->assertSame(['min' => 20, 'max' => 30], $service->levelOffsetRange('hero'));
        $this->assertSame(['min' => 35, 'max' => 50], $service->levelOffsetRange('legend'));
    }

    public function test_target_level_and_stats_increase_for_legend_map_enemy(): void
    {
        $service = app(ExplorationMapDifficultyService::class);
        $enemy = new Enemy([
            'level' => 40,
            'max_hp' => 100,
            'str' => 50,
            'def' => 40,
            'agi' => 30,
            'mag' => 20,
            'spr' => 20,
            'luk' => 10,
        ]);

        $targetLevel = $service->targetLevel($enemy, ['enemy_level' => 80], 'legend');
        $service->applyToEnemy($enemy, $targetLevel);

        $this->assertSame(80, $enemy->level);
        $this->assertSame(260, $enemy->max_hp);
        $this->assertSame(90, $enemy->str);
        $this->assertSame(72, $enemy->def);
    }

    public function test_extreme_threat_tiers_are_named_through_level_255(): void
    {
        $service = app(ExplorationMapDifficultyService::class);

        $this->assertSame('奈落級', $service->threatTier(180)['name']);
        $this->assertSame('滅界級', $service->threatTier(210)['name']);
        $this->assertSame('神話級', $service->threatTier(255)['name']);
    }

    public function test_mythic_map_can_set_a_higher_maximum_entry_fee(): void
    {
        $map = new ExplorationMap(['map_level' => 240, 'map_grade' => 'legend']);
        $registration = new TownMapRegistration();
        $registration->setRelation('map', $map);

        $this->assertSame(9072, app(MapPublicationService::class)->maxFee($registration));
    }
}
