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
        // 本番環境デプロイ時に、更新された敵データと職業データを反映する
        // This migration updated a populated legacy database. A fresh database
        // receives its enemy master from the seeders after all areas exist.
        if (Schema::hasTable('areas')
            && Schema::hasTable('enemies')
            && \Illuminate\Support\Facades\DB::table('areas')->exists()
            && file_exists(base_path('update_enemies_data.php'))) {
            require_once base_path('update_enemies_data.php');
        }
        
        if (file_exists(base_path('fix_basic_jobs.php'))) {
            require_once base_path('fix_basic_jobs.php');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
