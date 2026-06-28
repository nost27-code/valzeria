<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('job_classes', 'normal_attack_type')) {
                $table->string('normal_attack_type', 16)->default('physical')->after('affinity_magical');
            }
        });

        Schema::table('champ_states', function (Blueprint $table) {
            if (!Schema::hasColumn('champ_states', 'normal_attack_type')) {
                $table->string('normal_attack_type', 16)->default('physical')->after('affinity_magical');
            }
        });

        DB::table('champ_states')
            ->whereIn('job_name', [
                '魔法使い',
                '僧侶',
                '魔法剣士',
                '魔盗士',
                '魔弓士',
                '司祭',
                '錬金術師',
                '大賢者',
                '神官戦士',
                '賢商王',
                '古代錬成王',
                '時空王',
            ])
            ->update(['normal_attack_type' => 'magical']);

        // 全カラムが揃ったのでジョブデータを投入
        Artisan::call('db:seed', ['--class' => 'JobSystemSeeder', '--force' => true]);
    }

    public function down(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            if (Schema::hasColumn('job_classes', 'normal_attack_type')) {
                $table->dropColumn('normal_attack_type');
            }
        });

        Schema::table('champ_states', function (Blueprint $table) {
            if (Schema::hasColumn('champ_states', 'normal_attack_type')) {
                $table->dropColumn('normal_attack_type');
            }
        });
    }
};
