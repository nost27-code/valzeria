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
            if (!Schema::hasColumn('skills', 'activation_rate')) {
                $table->unsignedTinyInteger('activation_rate')->default(0)->after('mp_cost');
            }
            if (!Schema::hasColumn('skills', 'sp_cost_base')) {
                $table->unsignedSmallInteger('sp_cost_base')->default(0)->after('activation_rate');
            }
            if (!Schema::hasColumn('skills', 'sp_cost_rate')) {
                $table->decimal('sp_cost_rate', 5, 4)->default(0)->after('sp_cost_base');
            }
        });

        $masterPath = base_path('database/data/job_special_skills.php');
        if (!file_exists($masterPath)) {
            return;
        }

        foreach (require $masterPath as $row) {
            $job = DB::table('job_classes')->where('key', $row['job_key'])->first();
            if (!$job) {
                continue;
            }

            DB::table('skills')
                ->where('job_id', $job->id)
                ->update([
                    'name' => $row['special_name'],
                    'trigger_rate' => (int) $row['activation_rate'],
                    'activation_rate' => (int) $row['activation_rate'],
                    'sp_cost_base' => (int) $row['sp_cost_base'],
                    'sp_cost_rate' => (float) $row['sp_cost_rate'],
                    'mp_cost' => 0,
                    'damage_type' => $row['damage_type'],
                    'power_multiplier' => (float) $row['power_multiplier'],
                    'hit_count' => (int) $row['hit_count'],
                    'heal_percent' => (int) ($row['heal_percent'] ?? 0),
                    'self_damage_percent' => (int) ($row['self_damage_percent'] ?? 0),
                    'gold_bonus_percent' => 0,
                    'drop_bonus_percent' => (int) ($row['drop_bonus_percent'] ?? 0),
                    'def_ignore_percent' => (int) ($row['def_ignore_percent'] ?? 0),
                    'damage_reduction_percent' => (int) ($row['damage_reduction_percent'] ?? 0),
                    'enemy_def_down_percent' => (int) ($row['enemy_def_down_percent'] ?? 0),
                    'enemy_spr_down_percent' => (int) ($row['enemy_spr_down_percent'] ?? 0),
                    'enemy_spd_down_percent' => (int) ($row['enemy_spd_down_percent'] ?? 0),
                    'mp_recover_percent' => (int) ($row['mp_recover_percent'] ?? 0),
                    'description' => $row['description'],
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            foreach (['sp_cost_rate', 'sp_cost_base', 'activation_rate'] as $column) {
                if (Schema::hasColumn('skills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
