<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            if (!Schema::hasColumn('skills', 'enemy_atk_down_percent')) {
                $table->unsignedInteger('enemy_atk_down_percent')->default(0)->after('damage_reduction_percent');
            }
            if (!Schema::hasColumn('skills', 'enemy_mag_down_percent')) {
                $table->unsignedInteger('enemy_mag_down_percent')->default(0)->after('enemy_atk_down_percent');
            }
            if (!Schema::hasColumn('skills', 'rare_bonus_percent')) {
                $table->unsignedInteger('rare_bonus_percent')->default(0)->after('drop_bonus_percent');
            }
            if (!Schema::hasColumn('skills', 'drain_hp_rate')) {
                $table->decimal('drain_hp_rate', 4, 2)->default(0)->after('mp_recover_percent');
            }
            if (!Schema::hasColumn('skills', 'extra_hit_chance_percent')) {
                $table->unsignedInteger('extra_hit_chance_percent')->default(0)->after('hit_count');
            }
            if (!Schema::hasColumn('skills', 'luk_power_rate')) {
                $table->decimal('luk_power_rate', 4, 2)->default(0)->after('extra_hit_chance_percent');
            }
            if (!Schema::hasColumn('skills', 'hybrid_scaling')) {
                $table->string('hybrid_scaling', 16)->default('average')->after('luk_power_rate');
            }
            if (!Schema::hasColumn('skills', 'self_buff_percent')) {
                $table->unsignedInteger('self_buff_percent')->default(0)->after('damage_reduction_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            foreach ([
                'self_buff_percent',
                'hybrid_scaling',
                'luk_power_rate',
                'extra_hit_chance_percent',
                'drain_hp_rate',
                'rare_bonus_percent',
                'enemy_mag_down_percent',
                'enemy_atk_down_percent',
            ] as $column) {
                if (Schema::hasColumn('skills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
