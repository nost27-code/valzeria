<?php

namespace Tests\Feature;

use App\Livewire\Admin\RegionDepthDungeonManager;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterRegionDungeonRun;
use App\Models\City;
use App\Models\RegionDepthDungeon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegionDepthDungeonManagerAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_region_depth_dungeon_balance_metrics_and_recent_runs(): void
    {
        $city = City::query()->firstOrCreate(['id' => 1], ['name' => '検証街']);
        $area = Area::query()->create(['name' => '検証坑道', 'slug' => 'test-region-depth-analytics', 'city_id' => $city->id]);
        $dungeon = RegionDepthDungeon::query()->create([
            'key' => 'test-region-depth-analytics',
            'name' => '検証深坑',
            'city_id' => $city->id,
            'area_id' => $area->id,
            'source_area_id' => $area->id,
            'baseline_area_id' => $area->id,
            'entry_materials' => [],
            'base_stat_multipliers' => [],
        ]);
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '分析冒険者']);

        CharacterRegionDungeonRun::query()->create(['character_id' => $character->id, 'dungeon_key' => $dungeon->key, 'area_id' => $area->id, 'status' => 'returned', 'entered_at' => now()->subHours(26), 'ended_at' => now()->subHours(25), 'max_danger_rate' => 100, 'max_chain_count' => 5, 'total_exp' => 1000, 'total_job_exp' => 3]);
        CharacterRegionDungeonRun::query()->create(['character_id' => $character->id, 'dungeon_key' => $dungeon->key, 'area_id' => $area->id, 'status' => 'defeated', 'entered_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30), 'max_danger_rate' => 300, 'max_chain_count' => 10, 'total_exp' => 3000, 'total_job_exp' => 5]);
        CharacterRegionDungeonRun::query()->create(['character_id' => $character->id, 'dungeon_key' => $dungeon->key, 'area_id' => $area->id, 'status' => 'active', 'entered_at' => now()->subMinutes(10), 'max_danger_rate' => 25, 'max_chain_count' => 1, 'total_exp' => 100, 'total_job_exp' => 1]);

        Livewire::test(RegionDepthDungeonManager::class)
            ->assertSee('探索状況')
            ->assertSee('直近の潜行')
            ->assertSee('2')
            ->assertSee('3 / 1')
            ->assertSee('50.0%')
            ->assertSee('200.0% / 7.5')
            ->assertSee('2,000 / 4.0')
            ->assertSee('分析冒険者');
    }
}
