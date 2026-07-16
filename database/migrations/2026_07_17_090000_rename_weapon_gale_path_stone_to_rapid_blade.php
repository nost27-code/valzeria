<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_BR_WPN_GALE_PATH';

    public function up(): void
    {
        $this->renameIngredient('迅刃の導石');
    }

    public function down(): void
    {
        $this->renameIngredient('疾風の導石');
    }

    private function renameIngredient(string $name): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')
            ->where('ingredient_id', self::MATERIAL_CODE)
            ->update([
                'ingredient_name' => $name,
                'updated_at' => now(),
            ]);
    }
};
