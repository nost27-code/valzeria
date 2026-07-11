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

        app(FerdiaRegionSeeder::class)->seedStoryBranches();
    }

    public function down(): void
    {
        // Player progress and battle records may already reference these areas; do not remove them on rollback.
    }
};
