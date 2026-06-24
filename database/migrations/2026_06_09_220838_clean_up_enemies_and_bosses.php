<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Delete any enemies that exceed the actual master data (ID 424)
        DB::table('enemies')->where('id', '>', 424)->delete();
        DB::table('enemy_drops')->where('enemy_id', '>', 424)->delete();

        // Ensure the EnemySeeder runs on the live server to overwrite any corrupted/duplicate boss stats
        // in the 351-382 range with the clean data from monster_master.md
        Artisan::call('db:seed', [
            '--class' => 'EnemySeeder',
            '--force' => true
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration
    }
};
