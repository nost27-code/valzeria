<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HYBRID_JOBS = [
        'magic_archer' => '魔弓士',
        'hero' => '勇者',
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
            DB::table('job_classes')->where('key', 'magic_archer')->update(['normal_attack_type' => 'magical']);
            DB::table('job_classes')->where('key', 'hero')->update(['normal_attack_type' => 'physical']);
        }

        if (Schema::hasTable('champ_states') && Schema::hasColumn('champ_states', 'normal_attack_type')) {
            DB::table('champ_states')->where('job_name', '魔弓士')->update(['normal_attack_type' => 'magical']);
            DB::table('champ_states')->where('job_name', '勇者')->update(['normal_attack_type' => 'physical']);
        }
    }
};
