<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyRows($this->rows('new'), 'up');
    }

    public function down(): void
    {
        $this->applyRows($this->rows('old'), 'down');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(string $direction): array
    {
        $path = database_path('data/job_art_rank5_v6_1_migration.json');
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $rows = $payload[$direction] ?? null;
        if (! is_array($rows) || count($rows) !== 94) {
            throw new RuntimeException("Rank5 v6.1 {$direction} data must contain exactly 94 rows.");
        }

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function applyRows(array $rows, string $direction): void
    {
        if (! Schema::hasTable('skills')) {
            return;
        }

        DB::transaction(function () use ($rows, $direction): void {
            $targetQuery = DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('learn_rank', 5)
                ->whereIn('job_id', array_map(
                    static fn (string $key): int => (int) explode(':', $key, 2)[0],
                    array_keys($rows),
                ));
            $targetCount = (clone $targetQuery)->count();

            // Fresh installations run the hero backfill migration first, so
            // exactly jobs 70..79 can exist before the complete JobArtSeeder.
            if ($targetCount === 0) {
                return;
            }
            $bootstrapJobIds = (clone $targetQuery)
                ->orderBy('job_id')
                ->pluck('job_id')
                ->map(static fn (mixed $jobId): int => (int) $jobId)
                ->all();
            if ($bootstrapJobIds === range(70, 79)) {
                return;
            }
            if ($targetCount !== 94) {
                throw new RuntimeException(sprintf(
                    'Rank5 v6.1 %s aborted: expected 94 target rows, found %d.',
                    $direction,
                    $targetCount,
                ));
            }

            foreach ($rows as $naturalKey => $values) {
                [$jobId, $learnRank] = array_map('intval', explode(':', $naturalKey, 2));
                $query = DB::table('skills')
                    ->where('job_id', $jobId)
                    ->where('learn_rank', $learnRank)
                    ->where('skill_type', 'job_art');
                $count = (clone $query)->count();
                if ($count !== 1) {
                    throw new RuntimeException(sprintf(
                        'Rank5 v6.1 %s aborted: expected exactly one row for %s, found %d.',
                        $direction,
                        $naturalKey,
                        $count,
                    ));
                }

                if (Schema::hasColumn('skills', 'updated_at')) {
                    $values['updated_at'] = now();
                }
                $query->update($values);
            }
        });
    }
};
