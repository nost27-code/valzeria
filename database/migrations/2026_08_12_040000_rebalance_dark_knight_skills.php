<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_KEY = 'dark_knight';

    private const SPECIAL_NAME = '冥血斬';

    private const JOB_ART_NAME = '暗黒剣';

    private const OLD_SPECIAL_DESCRIPTION = '2.35倍攻撃。反動で最大HPの7%ダメージ';

    private const NEW_SPECIAL_DESCRIPTION = 'ATK依存の2.20倍物理攻撃。敵ATKを10%低下';

    private const OLD_JOB_ART_DESCRIPTION = '単体大ダメージ＋与ダメの一部を吸収。反動で最大HP5%ダメージ';

    private const NEW_JOB_ART_DESCRIPTION = 'ATK依存の1.85倍物理攻撃。与ダメージの35%を吸収し、反動で最大HP5%ダメージ';

    /** Apply the approved Dark Knight special and job-art balance. */
    public function up(): void
    {
        $this->updateDarkKnightSkills(
            specialPower: 2.20,
            specialSelfDamagePercent: 0,
            enemyAtkDownPercent: 10,
            specialDescription: self::NEW_SPECIAL_DESCRIPTION,
            jobArtDamageType: 'physical',
            jobArtDescription: self::NEW_JOB_ART_DESCRIPTION,
        );
    }

    public function down(): void
    {
        $this->updateDarkKnightSkills(
            specialPower: 2.35,
            specialSelfDamagePercent: 7,
            enemyAtkDownPercent: 0,
            specialDescription: self::OLD_SPECIAL_DESCRIPTION,
            jobArtDamageType: 'magical',
            jobArtDescription: self::OLD_JOB_ART_DESCRIPTION,
        );
    }

    private function updateDarkKnightSkills(
        float $specialPower,
        int $specialSelfDamagePercent,
        int $enemyAtkDownPercent,
        string $specialDescription,
        string $jobArtDamageType,
        string $jobArtDescription,
    ): void {
        if (! Schema::hasTable('job_classes') || ! Schema::hasTable('skills')) {
            return;
        }

        $jobId = DB::table('job_classes')
            ->where('key', self::JOB_KEY)
            ->value('id');

        if ($jobId === null) {
            return;
        }

        DB::table('skills')
            ->where('job_id', $jobId)
            ->where('skill_type', 'special')
            ->where('name', self::SPECIAL_NAME)
            ->update([
                'power_multiplier' => $specialPower,
                'self_damage_percent' => $specialSelfDamagePercent,
                'enemy_atk_down_percent' => $enemyAtkDownPercent,
                'description' => $specialDescription,
                'updated_at' => now(),
            ]);

        DB::table('skills')
            ->where('job_id', $jobId)
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->where('name', self::JOB_ART_NAME)
            ->update([
                'damage_type' => $jobArtDamageType,
                'power' => 185,
                'power_multiplier' => 1.85,
                'drain_hp_rate' => 0.35,
                'self_damage_percent' => 5,
                'description' => $jobArtDescription,
                'memo' => $jobArtDescription,
                'updated_at' => now(),
            ]);
    }
};
