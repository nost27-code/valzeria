<?php

namespace Tests\Unit;

use App\Models\ExplorationMap;
use App\Services\MapSurveyService;
use Tests\TestCase;

class MapSurveyServiceTest extends TestCase
{
    public function test_survey_cost_depends_on_map_grade(): void
    {
        $service = app(MapSurveyService::class);

        foreach (['normal' => 500, 'rare' => 1500, 'hero' => 5000, 'legend' => 10000] as $grade => $expected) {
            $map = new ExplorationMap(['map_grade' => $grade]);

            $this->assertSame($expected, $service->cost($map));
        }
    }
}
