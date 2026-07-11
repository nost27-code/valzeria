<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HYBRID_JOBS = [
        'magic_swordsman' => '魔法剣士',
        'magic_thief' => '魔盗士',
        'magic_bow_general' => '魔弓将',
    ];

    public function up(): void
    {
        if (Schema::hasTable('job_classes') && Schema::hasColumn('job_classes', 'normal_attack_type')) {
            DB::table('job_classes')
                ->whereIn('key', array_keys(self::HYBRID_JOBS))
                ->update(['normal_attack_type' => 'adaptive']);
        }

        if (Schema::hasTable('champ_states') && Schema::hasColumn('champ_states', 'normal_attack_type')) {
            DB::table('champ_states')
                ->whereIn('job_name', array_values(self::HYBRID_JOBS))
                ->update(['normal_attack_type' => 'adaptive']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_classes') && Schema::hasColumn('job_classes', 'normal_attack_type')) {
            DB::table('job_classes')
                ->whereIn('key', array_keys(self::HYBRID_JOBS))
                ->update(['normal_attack_type' => 'magical']);
        }

        if (Schema::hasTable('champ_states') && Schema::hasColumn('champ_states', 'normal_attack_type')) {
            DB::table('champ_states')
                ->whereIn('job_name', array_values(self::HYBRID_JOBS))
                ->update(['normal_attack_type' => 'magical']);
        }
    }
};
