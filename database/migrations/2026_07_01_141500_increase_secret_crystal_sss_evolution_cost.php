<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateWeaponSecretCrystals(5, 20);
        $this->updateArmorSecretCrystals(5, 20);
    }

    public function down(): void
    {
        $this->updateWeaponSecretCrystals(20, 5);
        $this->updateArmorSecretCrystals(20, 5);
    }

    private function updateWeaponSecretCrystals(int $from, int $to): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->where('from_rank', 'SS')
            ->where('to_rank', 'SSS')
            ->pluck('recipe_id');

        if ($recipeIds->isEmpty()) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->where('quantity', $from)
            ->where(function ($query) {
                $query->where('ingredient_name', 'like', '%秘境晶%')
                    ->orWhere('ingredient_id', 'like', '%_SECRET');
            })
            ->update([
                'quantity' => $to,
                'updated_at' => now(),
            ]);
    }

    private function updateArmorSecretCrystals(int $from, int $to): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->where('from_rank', 'SS')
            ->where('to_rank', 'SSS')
            ->pluck('evolution_recipe_id');

        if ($recipeIds->isEmpty()) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('evolution_recipe_id', $recipeIds)
            ->where('required_quantity', $from)
            ->where(function ($query) {
                $query->where('material_name', 'like', '%秘境晶%')
                    ->orWhere('material_id', 'like', '%_SECRET');
            })
            ->update([
                'required_quantity' => $to,
                'updated_at' => now(),
            ]);
    }
};
