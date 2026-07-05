<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUIREMENTS = [
        '聖剣将' => ['剣聖', '盾聖'],
        '黒炎騎士' => ['暗黒騎士', '大賢者'],
        '蒼天竜騎士' => ['竜騎士', '魔弓将'],
        '星詠み賢者' => ['大賢者', '詩聖'],
        '影縫い' => ['影狩人', '幻影王'],
        '鋼機導師' => ['機工王', '大錬金術師'],
        '聖域守護者' => ['神官戦士', '盾聖'],
        '黄金錬師' => ['黄金商人', '大錬金術師', '薬聖'],
        '雷拳覇' => ['武神', '剣聖', '竜騎士'],
        '戦陣軍師' => ['勇者', '戦略王', '機工王'],
    ];

    private const WEAPON_PERMISSIONS = [
        '聖剣将' => ['sword', 'spear', 'staff'],
        '黒炎騎士' => ['sword', 'axe', 'spear', 'magic_device'],
        '蒼天竜騎士' => ['spear', 'sword', 'axe', 'bow'],
        '星詠み賢者' => ['staff', 'magic_device', 'bow'],
        '影縫い' => ['dagger', 'katana', 'bow', 'gun'],
        '鋼機導師' => ['gun', 'magic_device', 'axe', 'spear', 'staff'],
        '聖域守護者' => ['sword', 'spear', 'staff', 'magic_device'],
        '黄金錬師' => ['magic_device', 'staff', 'gun', 'dagger'],
        '雷拳覇' => ['fist', 'axe', 'spear', 'sword'],
        '戦陣軍師' => ['sword', 'staff', 'magic_device', 'gun'],
    ];

    private const ARMOR_PERMISSIONS = [
        '聖剣将' => ['robe', 'light_armor', 'heavy_armor'],
        '黒炎騎士' => ['robe', 'cloak', 'light_armor', 'heavy_armor'],
        '蒼天竜騎士' => ['clothes', 'light_armor', 'heavy_armor'],
        '星詠み賢者' => ['clothes', 'robe', 'cloak'],
        '影縫い' => ['clothes', 'cloak', 'light_armor'],
        '鋼機導師' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
        '聖域守護者' => ['robe', 'cloak', 'light_armor', 'heavy_armor'],
        '黄金錬師' => ['clothes', 'robe', 'cloak', 'light_armor'],
        '雷拳覇' => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
        '戦陣軍師' => ['clothes', 'robe', 'cloak', 'light_armor', 'heavy_armor'],
    ];

    private const DESCRIPTIONS = [
        '聖剣将' => '剣と盾、二つの極致を結び、聖なる戦線を切り開く超級職。',
        '黒炎騎士' => '闇の剣技と深い魔導を重ね、黒炎で戦場を焼く超級職。',
        '蒼天竜騎士' => '竜の機動と魔弓の制圧力を併せ持つ、蒼空を駆ける超級職。',
        '星詠み賢者' => '星の理と詩の響きを読み解き、魔導を導く超級職。',
        '影縫い' => '影と幻を縫い止め、敵の隙を断ち切る超級職。',
        '鋼機導師' => '機工と錬金を束ね、鋼の術式で戦場を組み替える超級職。',
        '聖域守護者' => '神官の祈りと盾聖の守りで仲間を護る超級職。',
        '黄金錬師' => '財と錬成と薬学を黄金の術式へ昇華する超級職。',
        '雷拳覇' => '武神の拳、剣聖の刃、竜騎士の突進を雷へ束ねる超級職。',
        '戦陣軍師' => '勇気、戦略、機工をひとつの戦陣へ組み上げる超級職。',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('job_classes') || ! Schema::hasTable('job_requirements')) {
            return;
        }

        $jobs = DB::table('job_classes')
            ->whereIn('name', array_keys(self::REQUIREMENTS))
            ->pluck('id', 'name');
        $allJobs = DB::table('job_classes')->pluck('id', 'name');
        $now = now();

        foreach (self::REQUIREMENTS as $jobName => $requiredJobNames) {
            $jobId = $jobs[$jobName] ?? null;
            if (! $jobId) {
                continue;
            }

            DB::table('job_classes')
                ->where('id', $jobId)
                ->update([
                    'rank' => 'super',
                    'category' => '超級職',
                    'description' => self::DESCRIPTIONS[$jobName] ?? null,
                    'is_hidden' => true,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

            DB::table('job_requirements')->where('job_id', $jobId)->delete();
            foreach ($requiredJobNames as $requiredJobName) {
                $requiredJobId = $allJobs[$requiredJobName] ?? null;
                if (! $requiredJobId) {
                    continue;
                }

                DB::table('job_requirements')->insert([
                    'job_id' => $jobId,
                    'requirement_type' => 'master_job',
                    'required_job_id' => $requiredJobId,
                    'required_value' => null,
                    'required_key' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->syncEquipmentPermissions($jobs, $now);
    }

    public function down(): void
    {
        if (Schema::hasTable('job_classes')) {
            DB::table('job_classes')
                ->whereIn('name', array_keys(self::REQUIREMENTS))
                ->update([
                    'is_active' => false,
                    'is_hidden' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncEquipmentPermissions($jobs, $now): void
    {
        if (Schema::hasTable('job_weapon_permissions')) {
            foreach (self::WEAPON_PERMISSIONS as $jobName => $categories) {
                $jobId = $jobs[$jobName] ?? null;
                if (! $jobId) {
                    continue;
                }

                foreach ($categories as $category) {
                    DB::table('job_weapon_permissions')->updateOrInsert(
                        ['job_id' => $jobId, 'weapon_category' => $category],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        }

        if (Schema::hasTable('job_armor_permissions')) {
            foreach (self::ARMOR_PERMISSIONS as $jobName => $categories) {
                $jobId = $jobs[$jobName] ?? null;
                if (! $jobId) {
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
    }
};
