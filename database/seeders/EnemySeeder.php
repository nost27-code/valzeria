<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Enemy;
use App\Services\Enemy\EnemyStatMetadataGuesser;

class EnemySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFile = base_path('docs/monster_master.md');
        if (!file_exists($csvFile)) {
            $this->command?->error('monster_master.md not found.');
            return;
        }

        $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = true;
        
        DB::beginTransaction();
        try {
            $guesser = app(EnemyStatMetadataGuesser::class);
            $hasStatGenerationColumns = Schema::hasColumn('enemies', 'family_key')
                && Schema::hasColumn('enemies', 'variant_key')
                && Schema::hasColumn('enemies', 'role_key')
                && Schema::hasColumn('enemies', 'is_stat_locked');

            foreach ($lines as $line) {
                if ($header) {
                    $header = false;
                    continue;
                }
                
                $data = explode("\t", $line);
                // データの整合性チェック
                if (count($data) < 26) continue; // 最低限ステータス系までは必要
                
                $id = trim($data[0]);
                if (!is_numeric($id)) continue;
                
                $isBoss = strtoupper(trim($data[2])) === 'TRUE';
                
                $values = [
                    'area_id' => (int)trim($data[9]),
                    'name' => trim($data[1]),
                    'level' => (int)trim($data[13]),
                    'max_hp' => (int)trim($data[16]),
                    'str' => (int)trim($data[17]),
                    'def' => (int)trim($data[18]),
                    'agi' => (int)trim($data[19]),
                    'mag' => (int)trim($data[20]),
                    'spr' => (int)trim($data[21]),
                    'luk' => (int)trim($data[22]),
                    'exp_reward' => (int)trim($data[23]),
                    'job_exp_reward' => (int)trim($data[24]),
                    'gold_reward' => (int)trim($data[25]),
                    'appearance_weight' => trim($data[26] ?? '') === '' ? 0 : (int)trim($data[26]),
                    'is_boss' => $isBoss,
                    'role' => trim($data[12] ?? ''),
                    'type_name' => trim($data[15] ?? ''),
                    'element' => trim($data[14] ?? ''),
                    'drop_type' => trim($data[28] ?? ''),
                    'action_pattern' => trim($data[31] ?? ''),
                    'sort_order' => 0,
                ];

                if ($hasStatGenerationColumns) {
                    $metadata = $guesser->guess($values);
                    $values += [
                        'enemy_level' => null,
                        'family_key' => $metadata['family_key'],
                        'variant_key' => $metadata['variant_key'],
                        'role_key' => $metadata['role_key'],
                        'stat_generation_version' => config('enemy_stat_generation.version'),
                        'is_stat_locked' => true,
                        'generated_at' => null,
                        'manual_adjustment_note' => $metadata['manual_adjustment_note'],
                    ];
                }

                Enemy::updateOrCreate(
                    ['id' => $id],
                    $values
                );
            }
            DB::commit();
            $this->command?->info('Enemies seeded successfully from monster_master.md.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command?->error('Error seeding enemies: ' . $e->getMessage());
        }
    }
}
