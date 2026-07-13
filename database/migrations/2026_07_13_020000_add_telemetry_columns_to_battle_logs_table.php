<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 撃破ターン・与/被ダメージ・装備状態・敵耐久倍率を列として保持する。
     * log_textの都度パースなしで、武器バランス・敵バランスの効果測定を集計できるようにする。
     * 既存行へのバックフィルは行わない（新規列はnullable、既存ログはnullのまま）。
     */
    public function up(): void
    {
        Schema::table('battle_logs', function (Blueprint $table) {
            $table->unsignedInteger('turn_count')->nullable()->after('log_text');
            $table->unsignedInteger('damage_dealt')->nullable()->after('turn_count');
            $table->unsignedInteger('damage_taken')->nullable()->after('damage_dealt');
            $table->unsignedInteger('start_hp')->nullable()->after('damage_taken');
            $table->unsignedInteger('end_hp')->nullable()->after('start_hp');
            $table->string('weapon_rank', 16)->nullable()->after('end_hp');
            $table->unsignedInteger('pre_equipment_main_stat')->nullable()->after('weapon_rank');
            $table->boolean('has_engraving')->nullable()->after('pre_equipment_main_stat');
            $table->boolean('has_slayer')->nullable()->after('has_engraving');
            $table->decimal('enemy_hp_multiplier', 5, 3)->nullable()->after('has_slayer');
            $table->decimal('enemy_def_spr_multiplier', 5, 3)->nullable()->after('enemy_hp_multiplier');
            $table->decimal('enemy_atk_mag_multiplier', 5, 3)->nullable()->after('enemy_def_spr_multiplier');

            $table->index(['area_id', 'battle_type', 'result'], 'battle_logs_area_type_result_idx');
        });
    }

    public function down(): void
    {
        Schema::table('battle_logs', function (Blueprint $table) {
            $table->dropIndex('battle_logs_area_type_result_idx');
            $table->dropColumn([
                'turn_count', 'damage_dealt', 'damage_taken', 'start_hp', 'end_hp',
                'weapon_rank', 'pre_equipment_main_stat', 'has_engraving', 'has_slayer',
                'enemy_hp_multiplier', 'enemy_def_spr_multiplier', 'enemy_atk_mag_multiplier',
            ]);
        });
    }
};
