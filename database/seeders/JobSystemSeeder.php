<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\JobClass;
use App\Models\JobExpTable;
use App\Models\JobRequirement;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobSystemSeeder extends Seeder
{
    public function run(): void
    {
        $tsvPath = base_path('jobs_data.tsv');
        if (!file_exists($tsvPath)) {
            $this->command?->error("jobs_data.tsv not found.");
            return;
        }

        // 外部キーを一時的に無効化してクリア（sqlite/mysql対応）
        Schema::disableForeignKeyConstraints();
        JobClass::truncate();
        JobRequirement::truncate();
        JobExpTable::truncate();
        
        // job_master_bonusesテーブルがまだ存在する場合はクリアしておく
        if (Schema::hasTable('job_master_bonuses')) {
            DB::table('job_master_bonuses')->truncate();
        }
        if (Schema::hasTable('job_weapon_permissions')) {
            DB::table('job_weapon_permissions')->truncate();
        }
        if (Schema::hasTable('job_armor_permissions')) {
            DB::table('job_armor_permissions')->truncate();
        }
        Schema::enableForeignKeyConstraints();

        // 1. 経験値テーブル (レベル1〜20までの累計値)
        $expTables = [
            1 => 0, 2 => 10, 3 => 28, 4 => 56, 5 => 98,
            6 => 158, 7 => 240, 8 => 350, 9 => 495, 10 => 685,
            11 => 920, 12 => 1200, 13 => 1530, 14 => 1910, 15 => 2340,
            16 => 2820, 17 => 3350, 18 => 3930, 19 => 4560, 20 => 5250
        ];
        foreach ($expTables as $level => $exp) {
            JobExpTable::firstOrCreate(['job_level' => $level], ['required_exp' => $exp]);
        }

        // 2. 職業データのインポート
        $lines = file($tsvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $headerLine = array_shift($lines);
        $headers = array_map('trim', explode("\t", $headerLine));

        $jobsToRequirements = [];

        foreach ($lines as $line) {
            if (trim($line) === '') continue;

            $row = array_map('trim', explode("\t", $line));
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), null);
            }
            $row = array_slice($row, 0, count($headers));
            
            $data = array_combine($headers, $row);

            $key = trim($data['key'] ?? '');
            if (!$key) continue;

            // 必殺技率のパース（例：「3%」や「1%」から「%」を除外）
            $spSkillRate = trim($data['必殺技率'] ?? '0%');
            $spSkillRate = (int)str_replace('%', '', $spSkillRate);

            // JobClassにインサートする項目を整理
            $jobData = [
                'id' => (int)($data['job_id'] ?? 0),
                'name' => trim($data['職業名']),
                'rank' => strtolower(trim($data['ランク'])),
                'category' => trim($data['ランク表示'] ?? ''),
                'description' => trim($data['実装メモ'] ?? ''),
                'max_job_level' => (int)($data['最大Lv'] ?? 10),
                'hp_rate' => (int)($data['HP'] ?? 100),
                'mp_rate' => (int)($data['MP'] ?? 100),
                'atk_rate' => (int)($data['ATK'] ?? 100),
                'def_rate' => (int)($data['DEF'] ?? 100),
                'mag_rate' => (int)($data['MAG'] ?? 100),
                'spr_rate' => (int)($data['SPR'] ?? 100),
                'spd_rate' => (int)($data['SPD'] ?? 100),
                'luck_rate' => (int)($data['LUK'] ?? 100),
                'is_hidden' => strtolower(trim($data['隠し職'] ?? '')) === 'true',
                'is_active' => true,
                'sort_order' => (int)($data['job_id'] ?? 0) * 10,
                'bonus_hp' => (int)($data['HPボーナス'] ?? 0),
                'bonus_mp' => (int)($data['MPボーナス'] ?? 0),
                'bonus_str' => (int)($data['ATKボーナス'] ?? 0),
                'bonus_def' => (int)($data['DEFボーナス'] ?? 0),
                'bonus_mag' => (int)($data['MAGボーナス'] ?? 0),
                'bonus_spr' => (int)($data['SPRボーナス'] ?? 0),
                'bonus_spd' => (int)($data['SPDボーナス'] ?? 0),
                'bonus_luk' => (int)($data['LUKボーナス'] ?? 0),
                'bonus_gold_rate' => (int)($data['GOLD獲得%'] ?? 0),
                'bonus_drop_rate' => (int)($data['ドロップ率%'] ?? 0),
                'bonus_critical_rate' => (int)($data['必殺率%'] ?? 0),
                'special_skill_rate' => $spSkillRate,
                'affinity_physical' => (float)($data['戦型物理'] ?? 0.33),
                'affinity_speed'    => (float)($data['戦型速度'] ?? 0.34),
                'affinity_magical'  => (float)($data['戦型魔法'] ?? 0.33),
                'normal_attack_type' => $this->normalAttackType($key, $data['通常攻撃'] ?? null),
            ];

            $jobModel = JobClass::updateOrCreate(
                ['key' => $key],
                $jobData
            );

            // 解放条件の抽出（必要職1〜必要職3）
            $reqJobs = [];
            if (!empty(trim($data['必要職1'] ?? ''))) $reqJobs[] = trim($data['必要職1']);
            if (!empty(trim($data['必要職2'] ?? ''))) $reqJobs[] = trim($data['必要職2']);
            if (!empty(trim($data['必要職3'] ?? ''))) $reqJobs[] = trim($data['必要職3']);

            $reqItem = trim($data['必要アイテム'] ?? '');

            if (!empty($reqJobs) || $reqItem !== '') {
                $jobsToRequirements[$jobModel->id] = [
                    'req_jobs' => $reqJobs,
                    'req_item' => $reqItem
                ];
            }
        }

        // 3. 解放条件の登録
        foreach ($jobsToRequirements as $jobId => $reqData) {
            // 前提ジョブの登録
            foreach ($reqData['req_jobs'] as $reqJobName) {
                $reqJobName = trim($reqJobName);
                if ($reqJobName === '' || $reqJobName === 'NULL') continue;

                $reqJob = JobClass::where('name', $reqJobName)->first();
                if ($reqJob) {
                    JobRequirement::firstOrCreate([
                        'job_id' => $jobId,
                        'requirement_type' => 'master_job',
                        'required_job_id' => $reqJob->id
                    ]);
                } else {
                    Log::warning("JobSystemSeeder: Required job '$reqJobName' not found for job_id $jobId.");
                }
            }

            // 必要アイテムの登録
            $reqItemName = trim($reqData['req_item']);
            if ($reqItemName !== '' && $reqItemName !== 'NULL') {
                $reqItem = \App\Models\Item::where('name', $reqItemName)->first();
                if ($reqItem) {
                    JobRequirement::firstOrCreate([
                        'job_id' => $jobId,
                        'requirement_type' => 'item',
                        'required_value' => $reqItem->id, // required_valueにアイテムIDを保存
                    ]);
                } else {
                    Log::warning("JobSystemSeeder: Required item '$reqItemName' not found for job_id $jobId.");
                }
            }
        }

        $this->seedEquipmentPermissions();

        $this->command?->info("Job system data imported successfully.");
    }

    private function seedEquipmentPermissions(): void
    {
        if (!Schema::hasTable('job_weapon_permissions') || !Schema::hasTable('job_armor_permissions')) {
            return;
        }

        $weaponPermissions = [
            '剣士' => ['sword', 'dagger', 'spear'],
            '戦士' => ['sword', 'axe', 'spear', 'fist'],
            '盗賊' => ['dagger', 'sword', 'gun'],
            '弓使い' => ['bow', 'dagger', 'gun'],
            '格闘家' => ['fist', 'dagger'],
            '魔法使い' => ['staff', 'magic_device', 'dagger'],
            '僧侶' => ['staff', 'sword', 'magic_device'],
            '商人' => ['dagger', 'gun', 'staff'],
            '魔法剣士' => ['sword', 'dagger', 'staff', 'magic_device'],
            '聖騎士' => ['sword', 'spear', 'staff'],
            '侍' => ['katana', 'sword', 'dagger'],
            '軍師' => ['sword', 'staff', 'magic_device', 'gun'],
            '剣闘士' => ['sword', 'axe', 'spear', 'fist'],
            '狂戦士' => ['axe', 'sword', 'fist'],
            '守護騎士' => ['sword', 'spear', 'axe'],
            '傭兵' => ['sword', 'axe', 'dagger', 'bow', 'spear', 'gun'],
            '忍者' => ['dagger', 'katana', 'fist', 'gun'],
            '狙撃手' => ['bow', 'gun', 'dagger'],
            '魔盗士' => ['dagger', 'staff', 'magic_device', 'gun'],
            '旅商人' => ['dagger', 'gun', 'staff', 'bow'],
            'モンク' => ['fist', 'staff'],
            '魔弓士' => ['bow', 'staff', 'magic_device', 'dagger'],
            '吟遊詩人' => ['bow', 'dagger', 'staff'],
            '司祭' => ['staff', 'magic_device'],
            '薬師' => ['staff', 'dagger', 'magic_device'],
            '錬金術師' => ['magic_device', 'staff', 'gun'],
            '勇者' => ['sword', 'katana', 'spear', 'staff', 'bow'],
            '剣聖' => ['sword', 'katana', 'dagger'],
            '大賢者' => ['staff', 'magic_device'],
            '暗黒騎士' => ['sword', 'axe', 'spear', 'magic_device'],
            '黄金商人' => ['dagger', 'gun', 'staff', 'magic_device'],
            '竜騎士' => ['spear', 'sword', 'axe'],
            '武神' => ['fist', 'axe', 'spear'],
            '幻影王' => ['dagger', 'katana', 'staff', 'magic_device'],
            '機工王' => ['gun', 'magic_device', 'axe', 'spear'],
            '神官戦士' => ['sword', 'spear', 'staff', 'magic_device'],
            '影狩人' => ['bow', 'gun', 'dagger', 'katana'],
            '賢商王' => ['staff', 'magic_device', 'gun', 'dagger'],
            'ヴァルゼリアの英雄' => ['sword', 'axe', 'dagger', 'bow', 'staff', 'magic_device', 'gun', 'spear', 'fist', 'katana'],
            '深淵歩き' => ['sword', 'axe', 'dagger', 'katana', 'magic_device'],
            '古代錬成王' => ['magic_device', 'gun', 'staff', 'axe'],
            '竜神' => ['spear', 'sword', 'axe', 'fist'],
            '時空王' => ['staff', 'magic_device', 'dagger', 'katana', 'gun'],
        ];

        $armorPermissions = [
            '剣士' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '戦士' => ['clothes', 'light_armor', 'heavy_armor'],
            '盗賊' => ['clothes', 'cloak', 'light_armor'],
            '弓使い' => ['clothes', 'cloak', 'light_armor'],
            '格闘家' => ['clothes', 'cloak', 'light_armor'],
            '魔法使い' => ['clothes', 'robe', 'cloak'],
            '僧侶' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '商人' => ['clothes', 'cloak', 'light_armor'],
            '魔法剣士' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
            '聖騎士' => ['clothes', 'robe', 'light_armor', 'heavy_armor'],
            '侍' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '軍師' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '剣闘士' => ['clothes', 'light_armor', 'heavy_armor'],
            '狂戦士' => ['clothes', 'light_armor', 'heavy_armor'],
            '守護騎士' => ['light_armor', 'heavy_armor'],
            '傭兵' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '忍者' => ['clothes', 'cloak', 'light_armor'],
            '狙撃手' => ['clothes', 'cloak', 'light_armor'],
            '魔盗士' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '旅商人' => ['clothes', 'cloak', 'light_armor'],
            'モンク' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '魔弓士' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '吟遊詩人' => ['clothes', 'robe', 'cloak'],
            '司祭' => ['clothes', 'robe', 'cloak'],
            '薬師' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '錬金術師' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '勇者' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
            '剣聖' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '大賢者' => ['clothes', 'robe', 'cloak'],
            '暗黒騎士' => ['cloak', 'light_armor', 'heavy_armor'],
            '黄金商人' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '竜騎士' => ['light_armor', 'heavy_armor'],
            '武神' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '幻影王' => ['clothes', 'robe', 'cloak', 'light_armor'],
            '機工王' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
            '神官戦士' => ['robe', 'cloak', 'light_armor', 'heavy_armor'],
            '影狩人' => ['clothes', 'cloak', 'light_armor'],
            '賢商王' => ['clothes', 'robe', 'cloak', 'light_armor'],
            'ヴァルゼリアの英雄' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
            '深淵歩き' => ['robe', 'cloak', 'light_armor', 'heavy_armor'],
            '古代錬成王' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
            '竜神' => ['clothes', 'light_armor', 'heavy_armor'],
            '時空王' => ['clothes', 'robe', 'cloak', 'light_armor'],
        ];

        $jobs = JobClass::pluck('id', 'name');
        $now = now();

        foreach ($weaponPermissions as $jobName => $categories) {
            $jobId = $jobs[$jobName] ?? null;
            if (!$jobId) {
                continue;
            }

            foreach ($categories as $category) {
                DB::table('job_weapon_permissions')->updateOrInsert(
                    ['job_id' => $jobId, 'weapon_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        foreach ($armorPermissions as $jobName => $categories) {
            $jobId = $jobs[$jobName] ?? null;
            if (!$jobId) {
                continue;
            }

            foreach ($categories as $category) {
                DB::table('job_armor_permissions')->updateOrInsert(
                    ['job_id' => $jobId, 'armor_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    private function normalAttackType(string $jobKey, mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['physical', 'magical'], true)) {
            return $value;
        }

        return in_array($jobKey, [
            'mage',
            'priest',
            'magic_swordsman',
            'magic_thief',
            'magic_archer',
            'bishop',
            'alchemist',
            'grand_sage',
            'priest_warrior',
            'merchant_sage_king',
            'ancient_alchemist_king',
            'time_space_king',
        ], true) ? 'magical' : 'physical';
    }
}
