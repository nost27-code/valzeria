<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FinalWaveHeroTrialMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_180000_prepare_final_wave_hero_trials.php');
    }

    public function test_preparation_is_repeatable_and_rollback_preserves_earned_qualifications(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertSame(2, DB::table('areas')->whereIn('id', [92, 93])->count());
        foreach ([73 => 62, 76 => 61] as $heroId => $crownId) {
            $this->assertSame(1, DB::table('job_requirements')->where([
                'job_id' => $heroId, 'requirement_type' => 'master_job', 'required_job_id' => $crownId,
            ])->count());
        }
        $before = DB::table('job_classes')->whereIn('id', [73, 76])->orderBy('id')->get()->toArray();
        DB::table('areas')->whereIn('id', [92, 93])->update(['is_published' => true]);
        $migration->down();
        $this->assertSame(0, DB::table('areas')->whereIn('id', [92, 93])->where('is_published', true)->count());
        $this->assertEquals($before, DB::table('job_classes')->whereIn('id', [73, 76])->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('job_requirements', ['job_id' => 76, 'required_job_id' => 61]);
    }

    public function test_second_area_collision_rolls_back_the_first_area_and_job(): void
    {
        DB::table('areas')->where('id', 92)->update(['name' => '保持すべき名称']);
        DB::table('areas')->where('id', 93)->update(['slug' => 'unrelated_existing_area']);
        DB::table('job_classes')->where('id', 73)->update(['description' => '保持すべき説明']);
        try {
            $this->migration()->up();
            $this->fail('An occupied area ID must stop preparation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('area ID 93 is already used', $exception->getMessage());
        }
        $this->assertDatabaseHas('areas', ['id' => 92, 'name' => '保持すべき名称']);
        $this->assertDatabaseHas('job_classes', ['id' => 73, 'description' => '保持すべき説明']);
    }

    public function test_slug_collision_and_missing_crown_fail_without_partial_changes(): void
    {
        DB::table('areas')->where('id', 93)->update(['slug' => 'temporary_trial_slug']);
        DB::table('areas')->where('id', 92)->update(['slug' => 'phantom_funeral_hero_trial']);
        try {
            $this->migration()->up();
            $this->fail('Conflicting master data must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }
        $this->assertDatabaseHas('areas', ['id' => 93, 'slug' => 'temporary_trial_slug']);
        DB::table('areas')->where('id', 92)->update(['slug' => 'azure_dragon_hero_trial']);
        DB::table('areas')->where('id', 93)->update(['slug' => 'phantom_funeral_hero_trial']);
        DB::table('job_classes')->where('id', 61)->update(['key' => 'missing_crown']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job IDs are missing or inconsistent');
        $this->migration()->up();
    }
}
