<?php

use Database\Seeders\JobArtSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{0: int, 1: int}> */
    private const RELEASED_JOB_ID_RANGES = [
        [1, 38],
        [44, 79],
        [95, 99],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('job_classes') || ! Schema::hasTable('skills')) {
            return;
        }

        $jobIds = $this->releasedJobIds();
        $duplicateNaturalKeys = DB::table('skills')
            ->select(['job_id', 'learn_rank', 'skill_type'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereIn('job_id', $jobIds)
            ->where('skill_type', 'job_art')
            ->groupBy(['job_id', 'learn_rank', 'skill_type'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('job_id')
            ->orderBy('learn_rank')
            ->get();

        if ($duplicateNaturalKeys->isNotEmpty()) {
            $details = $duplicateNaturalKeys
                ->map(fn (object $row): string => sprintf(
                    'job_id=%d, learn_rank=%d, skill_type=%s, count=%d',
                    (int) $row->job_id,
                    (int) $row->learn_rank,
                    (string) $row->skill_type,
                    (int) $row->duplicate_count,
                ))
                ->implode(' | ');

            throw new \RuntimeException(
                'Released Job Art master sync aborted: duplicate natural key detected. '.$details
            );
        }

        $existingJobIds = DB::table('job_classes')
            ->whereIn('id', $jobIds)
            ->pluck('id')
            ->map(fn (mixed $jobId): int => (int) $jobId)
            ->sort()
            ->values()
            ->all();

        if ($existingJobIds !== $jobIds) {
            $missingJobIds = array_values(array_diff($jobIds, $existingJobIds));

            throw new \RuntimeException(
                'Released Job Art master sync aborted: released job master is missing. job_ids='.
                implode(',', $missingJobIds)
            );
        }

        DB::transaction(function () use ($jobIds): void {
            app(JobArtSeeder::class)->runForJobIds($jobIds);
        });
    }

    public function down(): void
    {
        // Intentional no-op. Do not restore stale master values or delete rows
        // that may already be referenced by player loadouts and presets.
    }

    /** @return list<int> */
    private function releasedJobIds(): array
    {
        $jobIds = [];

        foreach (self::RELEASED_JOB_ID_RANGES as [$first, $last]) {
            array_push($jobIds, ...range($first, $last));
        }

        return $jobIds;
    }
};
