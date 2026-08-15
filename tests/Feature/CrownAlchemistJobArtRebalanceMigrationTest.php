<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrownAlchemistJobArtRebalanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_updates_only_the_existing_rank_nine_power_and_is_idempotent(): void
    {
        $this->assertTrue(DB::table('job_classes')->where('id', 67)->exists());
        $skillId = (int) DB::table('skills')->insertGetId([
            'job_id' => 67,
            'name' => '金冠ミダスフィールド',
            'skill_type' => 'job_art',
            'learn_rank' => 9,
            'power' => 355,
            'power_multiplier' => 3.55,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = DB::table('skills')
            ->where('job_id', 67)
            ->where('learn_rank', 9)
            ->where('skill_type', 'job_art')
            ->where('name', '金冠ミダスフィールド');

        $row = (clone $query)->first(['id']);
        $this->assertNotNull($row);
        $migration = require base_path('database/migrations/2026_08_15_120000_rebalance_crown_alchemist_job_art.php');
        $migration->up();
        $migration->up();

        $updated = (clone $query)->first(['id', 'power', 'power_multiplier']);
        $this->assertNotNull($updated);
        $this->assertSame($skillId, (int) $updated->id);
        $this->assertSame(315, (int) $updated->power);
        $this->assertSame(3.15, (float) $updated->power_multiplier);
        $this->assertSame(1, $query->count());
    }

    public function test_migration_rejects_a_populated_skills_table_when_the_target_is_missing(): void
    {
        DB::table('skills')->insert([
            'job_id' => 66,
            'name' => '別の戦技',
            'skill_type' => 'job_art',
            'learn_rank' => 1,
        ]);

        $migration = require base_path('database/migrations/2026_08_15_120000_rebalance_crown_alchemist_job_art.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected exactly one Rank 9 Job Art row, found 0');
        $migration->up();
    }
}
