<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. job_classes から skill_id を削除しようとするとSQLiteでエラーになるため、一旦残す。使用しない。

        // 2. skills テーブルの再構築
        Schema::table('skills', function (Blueprint $table) {
            // 不要なカラムを削除
            $columnsToDrop = ['type', 'power', 'mp_cost', 'accuracy', 'priority', 'attribute', 'activation_rate', 'action_class'];
            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('skills', $col)) {
                    $table->dropColumn($col);
                }
            }

            // 新しいカラムを追加
            if (!Schema::hasColumn('skills', 'job_id')) {
                $table->foreignId('job_id')->nullable()->constrained('job_classes')->cascadeOnDelete()->after('id');
            }
            if (!Schema::hasColumn('skills', 'trigger_rate')) {
                $table->integer('trigger_rate')->default(2)->after('name');
            }
            if (!Schema::hasColumn('skills', 'damage_type')) {
                $table->string('damage_type')->default('physical')->after('trigger_rate');
            }
            if (!Schema::hasColumn('skills', 'power_multiplier')) {
                $table->decimal('power_multiplier', 5, 2)->default(1.00)->after('damage_type');
            }
            if (!Schema::hasColumn('skills', 'hit_count')) {
                $table->integer('hit_count')->default(1)->after('power_multiplier');
            }
            if (!Schema::hasColumn('skills', 'heal_percent')) {
                $table->integer('heal_percent')->default(0)->after('hit_count');
            }
            if (!Schema::hasColumn('skills', 'self_damage_percent')) {
                $table->integer('self_damage_percent')->default(0)->after('heal_percent');
            }
            if (!Schema::hasColumn('skills', 'gold_bonus_percent')) {
                $table->integer('gold_bonus_percent')->default(0)->after('self_damage_percent');
            }
            if (!Schema::hasColumn('skills', 'drop_bonus_percent')) {
                $table->integer('drop_bonus_percent')->default(0)->after('gold_bonus_percent');
            }
            if (!Schema::hasColumn('skills', 'def_ignore_percent')) {
                $table->integer('def_ignore_percent')->default(0)->after('drop_bonus_percent');
            }
            if (!Schema::hasColumn('skills', 'damage_reduction_percent')) {
                $table->integer('damage_reduction_percent')->default(0)->after('def_ignore_percent');
            }
            if (!Schema::hasColumn('skills', 'enemy_def_down_percent')) {
                $table->integer('enemy_def_down_percent')->default(0)->after('damage_reduction_percent');
            }
            if (!Schema::hasColumn('skills', 'enemy_spr_down_percent')) {
                $table->integer('enemy_spr_down_percent')->default(0)->after('enemy_def_down_percent');
            }
            if (!Schema::hasColumn('skills', 'enemy_spd_down_percent')) {
                $table->integer('enemy_spd_down_percent')->default(0)->after('enemy_spr_down_percent');
            }
            if (!Schema::hasColumn('skills', 'mp_recover_percent')) {
                $table->integer('mp_recover_percent')->default(0)->after('enemy_spd_down_percent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ロールバックは一旦省略（巨大な変更のため、ダウン時はテーブル再構築の方が良いため）
    }
};
