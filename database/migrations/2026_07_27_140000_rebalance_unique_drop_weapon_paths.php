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
        // This is a master-data balance adjustment. Restore a pre-release
        // database backup if the previous values must be reinstated.
    }
};
