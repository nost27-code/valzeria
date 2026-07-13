<?php

use App\Models\Area;
use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $area = Area::find(1029);
        if ($area !== null) {
            app(FerdiaRegionSeeder::class)->seedBosses([(int) $area->id => $area]);
        }
    }

    public function down(): void
    {
        // Preserve battle progress and the boss master when rolling back code.
    }
};
