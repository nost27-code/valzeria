<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private const TARGET_JOBS = [
        52 => '蒼天竜騎士',
        62 => '竜冠槍将',
        71 => '黒月の執行者',
        90 => '雷霆武神',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('job_classes') || ! Schema::hasTable('job_weapon_permissions')) {
            return;
        }

        $jobs = DB::table('job_classes')
            ->whereIn('id', array_keys(self::TARGET_JOBS))
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach (self::TARGET_JOBS as $jobId => $expectedName) {
            $job = $jobs->get($jobId);
            if (! $job || (string) $job->name !== $expectedName) {
                throw new \RuntimeException(
                    "High-rank bow proficiency target mismatch for job ID {$jobId}: expected {$expectedName}."
                );
            }
        }

        $now = now();
        $rows = collect(self::TARGET_JOBS)
            ->map(fn (string $_name, int $jobId): array => [
                'job_id' => $jobId,
                'weapon_category' => 'bow',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($rows): void {
            DB::table('job_weapon_permissions')->upsert(
                $rows,
                ['job_id', 'weapon_category'],
                ['updated_at']
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_weapon_permissions')) {
            return;
        }

        DB::table('job_weapon_permissions')
            ->whereIn('job_id', array_keys(self::TARGET_JOBS))
            ->where('weapon_category', 'bow')
            ->delete();
    }
};
