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

        $path = base_path('jobs_data.tsv');
        if (! is_file($path)) {
            return;
        }

        $now = now();
        foreach ($this->pendingJobs($path) as $job) {
            DB::table('job_classes')->updateOrInsert(
                ['id' => $job['id']],
                array_merge($this->filterExistingColumns('job_classes', $job['data']), [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        // Pending master-data addition only. Do not delete jobs that may become referenced later.
    }

    private function pendingJobs(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return [];
        }

        $headers = array_map('trim', explode("\t", array_shift($lines)));
        $jobs = [];

        foreach ($lines as $line) {
            $values = array_map('trim', explode("\t", $line));
            $values = array_pad($values, count($headers), '');
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            $id = (int) ($row['job_id'] ?? 0);

            if ($id < 49 || $id > 94) {
                continue;
            }

            $jobs[] = [
                'id' => $id,
                'data' => [
                    'key' => (string) $row['key'],
                    'name' => (string) $row['職業名'],
                    'rank' => strtolower((string) $row['ランク']),
                    'category' => (string) $row['ランク表示'],
                    'description' => (string) $row['実装メモ'],
                    'max_job_level' => (int) ($row['最大Lv'] ?: 10),
                    'hp_rate' => (int) $row['HP'],
                    'mp_rate' => (int) $row['MP'],
                    'atk_rate' => (int) $row['ATK'],
                    'def_rate' => (int) $row['DEF'],
                    'mag_rate' => (int) $row['MAG'],
                    'spr_rate' => (int) $row['SPR'],
                    'spd_rate' => (int) $row['SPD'],
                    'luck_rate' => (int) $row['LUK'],
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => $id * 10,
                    'bonus_hp' => (int) ($row['HPボーナス'] ?? 0),
                    'bonus_mp' => (int) ($row['MPボーナス'] ?? 0),
                    'bonus_str' => (int) ($row['ATKボーナス'] ?? 0),
                    'bonus_def' => (int) ($row['DEFボーナス'] ?? 0),
                    'bonus_mag' => (int) ($row['MAGボーナス'] ?? 0),
                    'bonus_spr' => (int) ($row['SPRボーナス'] ?? 0),
                    'bonus_spd' => (int) ($row['SPDボーナス'] ?? 0),
                    'bonus_luk' => (int) ($row['LUKボーナス'] ?? 0),
                    'bonus_gold_rate' => (int) ($row['GOLD獲得%'] ?? 0),
                    'bonus_drop_rate' => (int) ($row['ドロップ率%'] ?? 0),
                    'bonus_critical_rate' => (int) ($row['必殺率%'] ?? 0),
                    'special_skill_rate' => (int) str_replace('%', '', (string) ($row['必殺技率'] ?? '0')),
                    'affinity_physical' => (float) ($row['戦型物理'] ?? 0.33),
                    'affinity_speed' => (float) ($row['戦型速度'] ?? 0.34),
                    'affinity_magical' => (float) ($row['戦型魔法'] ?? 0.33),
                    'normal_attack_type' => in_array((string) ($row['通常攻撃'] ?? ''), ['physical', 'magical'], true)
                        ? (string) $row['通常攻撃']
                        : 'physical',
                ],
            ];
        }

        return $jobs;
    }

    private function filterExistingColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }
};
