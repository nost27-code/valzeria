<?php

namespace Tests\Unit;

use App\Models\ExplorationMap;
use App\Services\ExplorationMapDisplayService;
use App\Services\ExplorationMapLegacyRewardService;
use Tests\TestCase;

class ExplorationMapEquipmentDescriptionTest extends TestCase
{
    public function test_existing_map_displays_saved_bonus_even_when_current_grade_configuration_differs(): void
    {
        $this->mock(ExplorationMapLegacyRewardService::class)->shouldReceive('ancientFragmentFor')->andReturn(null);
        $map = new ExplorationMap([
            'map_grade' => 'legend', 'map_level' => 10, 'reward_profile' => 'equipment',
            'reward_modifiers_json' => ['equipment_drop_bonus_points' => ['weapon' => 0.10, 'armor' => 0.10, 'accessory' => 0.03]],
        ]);
        $details = app(ExplorationMapDisplayService::class)->details($map, collect());
        $this->assertSame('武器 ＋0.10ポイント ／ 防具 ＋0.10ポイント ／ 装飾品 ＋0.03ポイント', $details['equipment_bonus_description']);
        $html = view('exploration-maps.partials.equipment-bonus', ['description' => $details['equipment_bonus_description']])->render();
        $this->assertStringContainsString('1%なら1.10%', $html);
        $this->assertStringNotContainsString('＋0.30', $html);
    }

    public function test_map_without_saved_bonus_does_not_claim_an_equipment_bonus(): void
    {
        $this->mock(ExplorationMapLegacyRewardService::class)->shouldReceive('ancientFragmentFor')->andReturn(null);
        $map = new ExplorationMap(['map_grade' => 'rare', 'map_level' => 10, 'reward_profile' => 'equipment', 'reward_modifiers_json' => []]);
        $details = app(ExplorationMapDisplayService::class)->details($map, collect());
        $this->assertNull($details['equipment_bonus_description']);
        $this->assertSame('', trim(view('exploration-maps.partials.equipment-bonus', ['description' => null])->render()));
    }
}
