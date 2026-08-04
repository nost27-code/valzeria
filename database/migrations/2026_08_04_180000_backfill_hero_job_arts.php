<?php

use Database\Seeders\JobArtSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIRST_HERO_JOB_ID = 70;

    private const LAST_HERO_JOB_ID = 79;

    public function up(): void
    {
        if (!Schema::hasTable('job_classes') || !Schema::hasTable('skills')) {
            return;
        }

        $existingHeroJobIds = DB::table('job_classes')
            ->whereBetween('id', [self::FIRST_HERO_JOB_ID, self::LAST_HERO_JOB_ID])
            ->pluck('id')
            ->map(fn (mixed $jobId): int => (int) $jobId)
            ->all();

        if ($existingHeroJobIds === []) {
            return;
        }

        app(JobArtSeeder::class)->runForJobIds($existingHeroJobIds);
    }

    public function down(): void
    {
        // Corrective master-data backfill. Keep rows because player slot records may reference them.
    }
};
