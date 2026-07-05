<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('skills')
            || !Schema::hasColumn('skills', 'activation_phrase')
            || !Schema::hasColumn('skills', 'activation_description')) {
            return;
        }

        $path = base_path('database/data/job_arts.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $learnRank = (int) ($row['learn_rank'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $phrase = $this->nullableText($row['activation_phrase'] ?? null);
            $description = $this->nullableText($row['activation_description'] ?? null);

            if ($jobId <= 0 || $learnRank <= 0 || $name === '') {
                continue;
            }

            DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('job_id', $jobId)
                ->where('learn_rank', $learnRank)
                ->where('name', $name)
                ->update([
                    'activation_phrase' => $phrase,
                    'activation_description' => $description,
                ]);
        }
    }

    public function down(): void
    {
        // Data-only flavor text refresh; previous per-row text is not knowable.
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
};
