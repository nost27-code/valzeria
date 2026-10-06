<?php

namespace Tests\Feature;

use App\Models\Area;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SecondWaveHeroTrialMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reapplying_preparation_keeps_master_ids_and_requirements_unique(): void
    {
        $migration = require database_path('migrations/2026_09_09_120000_prepare_second_wave_hero_trials.php');
        $migration->up();
        $migration->up();
        $this->assertSame(4, DB::table('areas')->whereIn('id', [88, 89, 90, 91])->count());
        foreach ([74 => 65, 75 => 66, 78 => 68, 79 => 69] as $heroId => $crownId) {
            $this->assertSame(1, DB::table('job_requirements')->where([
                'job_id' => $heroId, 'requirement_type' => 'master_job', 'required_job_id' => $crownId,
            ])->count());
        }
    }

    public function test_last_area_id_collision_rolls_back_all_prior_master_updates(): void
    {
        Area::query()->whereKey(88)->update(['name' => '競合前の試練場']);
        Area::query()->whereKey(91)->update(['slug' => 'unrelated_existing_area']);
        DB::table('job_classes')->where('id', 74)->update(['description' => '競合前の職説明']);
        $migration = require database_path('migrations/2026_09_09_120000_prepare_second_wave_hero_trials.php');
        try {
            $migration->up();
            $this->fail('A conflicting area ID must stop preparation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('area ID 91 is already used', $exception->getMessage());
        }
        $this->assertDatabaseHas('areas', ['id' => 88, 'name' => '競合前の試練場']);
        $this->assertDatabaseHas('areas', ['id' => 91, 'slug' => 'unrelated_existing_area']);
        $this->assertDatabaseHas('job_classes', ['id' => 74, 'description' => '競合前の職説明']);
    }
}
