<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterRegionDungeonRecord;
use App\Models\CharacterRegionDungeonRun;
use App\Models\City;
use App\Models\Enemy;
use App\Models\PublicLog;
use App\Models\RegionDepthDungeon;
use App\Models\User;
use App\Services\RegionDepthDungeonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionDepthDungeonMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_definition_uses_the_selected_source_area_and_persisted_scaling(): void
    {
        $city = City::create(['name' => '試験街', 'description' => '', 'recommended_level_min' => 1, 'recommended_level_max' => 10, 'sort_order' => 1]);
        $source = $this->area($city, '敵候補地');
        $baseline = $this->area($city, '強さ基準地');
        $entry = $this->area($city, '追加深坑');
        $this->enemy($source, 100, 10);
        $this->enemy($baseline, 150, 20);

        $scaling = app(RegionDepthDungeonService::class)->baselineScaling($source, $baseline);
        RegionDepthDungeon::create([
            'key' => 'test-region-depth', 'name' => '試験深坑', 'city_id' => $city->id, 'area_id' => $entry->id,
            'source_area_id' => $source->id, 'baseline_area_id' => $baseline->id, 'is_enabled' => true,
            'entry_materials' => [], 'base_stat_multipliers' => $scaling['base_stat_multipliers'], 'base_exp_multiplier' => $scaling['base_exp_multiplier'],
        ]);

        $service = app(RegionDepthDungeonService::class);
        $this->assertSame($source->id, $service->sourceAreaFor('test-region-depth')?->id);
        $this->assertSame(1.5, $service->baseEnemyStatMultipliers('test-region-depth')['hp']);
        $this->assertSame(2.0, $service->baseExpMultiplier('test-region-depth'));
        $this->assertSame('test-region-depth', $service->keyForArea($entry));
    }

    public function test_leaderboard_keeps_personal_rank_separate_from_other_players(): void
    {
        $me = $this->character('自分');
        $first = $this->character('先行者');
        $third = $this->character('後続者');
        foreach ([[$me, 300], [$first, 500], [$third, 100]] as [$character, $danger]) {
            CharacterRegionDungeonRecord::create(['character_id' => $character->id, 'dungeon_key' => 'ranking-test', 'best_danger_rate' => $danger]);
        }

        $ranking = app(RegionDepthDungeonService::class)->leaderboard($me, 'ranking-test');

        $this->assertSame(2, $ranking['personal_rank']);
        $this->assertCount(2, $ranking['others']);
        $this->assertSame('先行者', $ranking['others'][0]['record']->character->name);
        $this->assertSame(1, $ranking['others'][0]['rank']);
    }

    public function test_only_top_five_new_danger_records_are_announced_publicly(): void
    {
        $city = City::create(['name' => '記録試験街', 'description' => '', 'recommended_level_min' => 1, 'recommended_level_max' => 10, 'sort_order' => 1]);
        $area = $this->area($city, '記録試験坑道');
        $challenger = $this->character('挑戦者');
        foreach ([1000, 900, 800, 700, 650] as $danger) {
            CharacterRegionDungeonRecord::create(['character_id' => $this->character('先行者' . $danger)->id, 'dungeon_key' => 'granberg_black_furnace', 'best_danger_rate' => $danger]);
        }

        CharacterRegionDungeonRun::create(['character_id' => $challenger->id, 'dungeon_key' => 'granberg_black_furnace', 'area_id' => $area->id, 'status' => 'active', 'entered_at' => now(), 'max_danger_rate' => 600]);
        app(RegionDepthDungeonService::class)->finalize($challenger, 'returned');
        $this->assertSame(0, PublicLog::query()->where('type', 'region_depth_dungeon')->count());

        CharacterRegionDungeonRun::create(['character_id' => $challenger->id, 'dungeon_key' => 'granberg_black_furnace', 'area_id' => $area->id, 'status' => 'active', 'entered_at' => now(), 'max_danger_rate' => 1100]);
        app(RegionDepthDungeonService::class)->finalize($challenger, 'returned');
        $this->assertSame(1, PublicLog::query()->where('type', 'region_depth_dungeon')->count());
    }

    private function area(City $city, string $name): Area
    {
        return Area::create(['name' => $name, 'slug' => 'test-' . uniqid(), 'city_id' => $city->id, 'recommended_level_min' => 1, 'recommended_level_max' => 10]);
    }

    private function enemy(Area $area, int $hp, int $exp): void
    {
        Enemy::create(['name' => '試験敵', 'area_id' => $area->id, 'level' => 1, 'max_hp' => $hp, 'str' => $hp, 'def' => $hp, 'agi' => $hp, 'mag' => $hp, 'spr' => $hp, 'luk' => $hp, 'exp_reward' => $exp, 'gold_reward' => 1, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
    }

    private function character(string $name): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => $name, 'hp_base' => 10, 'current_hp' => 10]);
    }
}
