<?php

use Database\Seeders\DropEquipmentAdditionsSeeder;
use Database\Seeders\DropWeaponEvolutionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        (new DropEquipmentAdditionsSeeder())->run();
        (new DropWeaponEvolutionSeeder())->run();

        $recipeIds = DB::table('weapon_evolution_recipes')
            ->where('from_rank', 'SS')
            ->where('to_rank', 'SSS')
            ->where('is_active', true)
            ->pluck('recipe_id');

        DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->where('ingredient_id', 'like', 'MAT_BR_WPN_%_SECRET')
            ->update(['quantity' => 20, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Player-owned weapons must not be removed automatically. Restore a
        // pre-release database backup if this master-data release is reverted.
    }
};
