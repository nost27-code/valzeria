<?php

use Database\Seeders\DropWeaponEvolutionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DropWeaponEvolutionSeeder())->run();
    }

    public function down(): void
    {
        // Player-owned evolved weapons must not be deleted automatically.
        // Restore the pre-release database backup if this master addition must be rolled back.
    }
};
