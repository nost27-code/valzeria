<?php

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('areas') || !Schema::hasTable('enemies') || !Schema::hasTable('area_discovery_links')) {
            return;
        }

        $seeder = app(FerdiaRegionSeeder::class);
        $seeder->seedBosses();
        $seeder->seedDiscoveryLinks();
    }

    public function down(): void
    {
        // Existing player clears and master data must remain intact on rollback.
    }
};
