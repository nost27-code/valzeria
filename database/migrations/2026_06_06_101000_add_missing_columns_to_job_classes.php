<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('job_classes', 'key')) {
                $table->string('key')->nullable()->unique();
            }
            if (!Schema::hasColumn('job_classes', 'rank')) {
                $table->enum('rank', ['normal', 'middle', 'advanced', 'legend'])->default('normal');
            }
            if (!Schema::hasColumn('job_classes', 'category')) {
                $table->string('category')->nullable();
            }
            if (!Schema::hasColumn('job_classes', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('job_classes', 'max_job_level')) {
                $table->unsignedTinyInteger('max_job_level')->default(10);
            }
            if (!Schema::hasColumn('job_classes', 'hp_rate')) {
                $table->unsignedSmallInteger('hp_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'mp_rate')) {
                $table->unsignedSmallInteger('mp_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'atk_rate')) {
                $table->unsignedSmallInteger('atk_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'def_rate')) {
                $table->unsignedSmallInteger('def_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'mag_rate')) {
                $table->unsignedSmallInteger('mag_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'spr_rate')) {
                $table->unsignedSmallInteger('spr_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'spd_rate')) {
                $table->unsignedSmallInteger('spd_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'luck_rate')) {
                $table->unsignedSmallInteger('luck_rate')->default(100);
            }
            if (!Schema::hasColumn('job_classes', 'is_hidden')) {
                $table->boolean('is_hidden')->default(false);
            }
            if (!Schema::hasColumn('job_classes', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('job_classes', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0);
            }
        });

        // ジョブデータの初期投入は全カラム追加後のマイグレーション(2026_06_17_230000)で行う
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 
    }
};
