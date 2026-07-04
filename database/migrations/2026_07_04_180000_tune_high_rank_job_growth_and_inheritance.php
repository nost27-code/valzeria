<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIGH_RANKS = ['super', 'crown', 'hero', 'legend', 'myth'];

    private const STAT_COLUMNS = [
        'HP' => 'hp_rate',
        'MP' => 'mp_rate',
        'ATK' => 'atk_rate',
        'DEF' => 'def_rate',
        'MAG' => 'mag_rate',
        'SPR' => 'spr_rate',
        'SPD' => 'spd_rate',
        'LUK' => 'luck_rate',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $path = base_path('jobs_data.tsv');
        if (! is_file($path)) {
            return;
        }

        $now = now();
        foreach ($this->highRankRows($path) as $row) {
            $payload = ['updated_at' => $now];
            foreach (self::STAT_COLUMNS as $sourceColumn => $dbColumn) {
                if (Schema::hasColumn('job_classes', $dbColumn)) {
                    $payload[$dbColumn] = (int) ($row[$sourceColumn] ?? 100);
                }
            }

            DB::table('job_classes')
                ->where('id', (int) $row['job_id'])
                ->update($payload);
        }
    }

    public function down(): void
    {
        // Balance-only master update. Keep current tuned values on rollback.
    }

    private function highRankRows(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return [];
        }

        $headers = array_map('trim', explode("\t", array_shift($lines)));
        $rows = [];

        foreach ($lines as $line) {
            $values = array_map('trim', explode("\t", $line));
            $values = array_pad($values, count($headers), '');
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            $rank = strtolower((string) ($row['ランク'] ?? ''));

            if (! in_array($rank, self::HIGH_RANKS, true)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }
};
