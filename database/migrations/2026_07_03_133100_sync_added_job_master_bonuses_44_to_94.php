<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        foreach ($this->bonusRows() as $jobId => $bonuses) {
            DB::table('job_classes')
                ->where('id', $jobId)
                ->update(array_merge($bonuses, ['updated_at' => now()]));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->whereBetween('id', [44, 94])
            ->update([
                'bonus_hp' => 0,
                'bonus_mp' => 0,
                'bonus_str' => 0,
                'bonus_def' => 0,
                'bonus_mag' => 0,
                'bonus_spr' => 0,
                'bonus_spd' => 0,
                'bonus_luk' => 0,
                'updated_at' => now(),
            ]);
    }

    private function bonusRows(): array
    {
        $path = base_path('jobs_data.tsv');
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $headers = array_map('trim', explode("\t", (string) array_shift($lines)));
        $index = array_flip($headers);
        $rows = [];

        foreach ($lines as $line) {
            $columns = explode("\t", $line);
            if (count($columns) < count($headers)) {
                $columns = array_pad($columns, count($headers), '');
            }

            $jobId = (int) ($columns[$index['job_id']] ?? 0);
            if ($jobId < 44 || $jobId > 94) {
                continue;
            }

            $rows[$jobId] = [
                'bonus_hp' => (int) ($columns[$index['HPボーナス']] ?? 0),
                'bonus_mp' => (int) ($columns[$index['MPボーナス']] ?? 0),
                'bonus_str' => (int) ($columns[$index['ATKボーナス']] ?? 0),
                'bonus_def' => (int) ($columns[$index['DEFボーナス']] ?? 0),
                'bonus_mag' => (int) ($columns[$index['MAGボーナス']] ?? 0),
                'bonus_spr' => (int) ($columns[$index['SPRボーナス']] ?? 0),
                'bonus_spd' => (int) ($columns[$index['SPDボーナス']] ?? 0),
                'bonus_luk' => (int) ($columns[$index['LUKボーナス']] ?? 0),
            ];
        }

        return $rows;
    }
};
