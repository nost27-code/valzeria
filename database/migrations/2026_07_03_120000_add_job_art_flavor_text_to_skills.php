<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            if (!Schema::hasColumn('skills', 'activation_phrase')) {
                $table->text('activation_phrase')->nullable()->after('mp_recover_percent');
            }
            if (!Schema::hasColumn('skills', 'activation_description')) {
                $table->text('activation_description')->nullable()->after('activation_phrase');
            }
        });

        $this->backfillJobArtFlavorText();
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            if (Schema::hasColumn('skills', 'activation_description')) {
                $table->dropColumn('activation_description');
            }
            if (Schema::hasColumn('skills', 'activation_phrase')) {
                $table->dropColumn('activation_phrase');
            }
        });
    }

    private function backfillJobArtFlavorText(): void
    {
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

            if ($jobId <= 0 || $learnRank <= 0 || $name === '' || ($phrase === null && $description === null)) {
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

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
};
