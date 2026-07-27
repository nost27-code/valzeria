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
        // Keep ranks on existing player-owned equipment masters. Roll back from a
        // pre-release database backup if the master-data release must be reverted.
    }
};
