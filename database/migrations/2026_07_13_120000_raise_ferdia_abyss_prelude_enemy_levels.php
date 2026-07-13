<?php

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FerdiaRegionSeeder::class)->seedAreaMaster(1029);
    }

    public function down(): void
    {
        // Balance master data is intentionally not rolled back automatically.
    }
};
