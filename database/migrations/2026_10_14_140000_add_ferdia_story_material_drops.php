<?php

use Database\Seeders\FerdiaStoryMaterialDropSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FerdiaStoryMaterialDropSeeder::class)->run();
    }

    public function down(): void
    {
        // Keep the restored drop sources and all acquired player materials.
    }
};
