<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QUANTITY_MAP = [
        'E' => [12 => 10, 5 => 4, 4 => 2],
        'D' => [18 => 12, 8 => 5, 5 => 2, 4 => 2],
        'C' => [24 => 16, 10 => 6, 6 => 4],
        'B' => [36 => 22, 14 => 8, 10 => 6, 8 => 4, 6 => 4],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->lightenWeaponQuantities();
            $this->lightenArmorQuantities();
            $this->lightenAccessoryQuantities();
        });
    }

    public function down(): void
    {
        // Balance simplification only. Previous heavier quantities are not restored.
    }

    private function lightenWeaponQuantities(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach (self::QUANTITY_MAP as $fromRank => $quantityMap) {
            $recipeIds = DB::table('weapon_evolution_recipes')
                ->where('is_active', true)
                ->where('recipe_id', 'not like', 'BR_%')
                ->where('from_rank', $fromRank)
                ->pluck('recipe_id');

            foreach ($quantityMap as $from => $to) {
                DB::table('weapon_evolution_recipe_ingredients')
                    ->whereIn('recipe_id', $recipeIds)
                    ->where('quantity', $from)
                    ->update([
                        'quantity' => $to,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function lightenArmorQuantities(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        foreach (self::QUANTITY_MAP as $fromRank => $quantityMap) {
            $recipeIds = DB::table('armor_evolution_recipes')
                ->where('is_active', true)
                ->where('evolution_recipe_id', 'not like', 'BR_%')
                ->where('from_rank', $fromRank)
                ->pluck('evolution_recipe_id');

            foreach ($quantityMap as $from => $to) {
                DB::table('armor_evolution_recipe_ingredients')
                    ->whereIn('evolution_recipe_id', $recipeIds)
                    ->where('required_quantity', $from)
                    ->update([
                        'required_quantity' => $to,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function lightenAccessoryQuantities(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        foreach (self::QUANTITY_MAP as $fromRank => $quantityMap) {
            $recipeIds = DB::table('accessory_evolution_recipes')
                ->where('is_active', true)
                ->where('recipe_id', 'not like', 'BR_%')
                ->where('from_rank', $fromRank)
                ->pluck('recipe_id');

            foreach ($quantityMap as $from => $to) {
                DB::table('accessory_evolution_recipe_ingredients')
                    ->whereIn('recipe_id', $recipeIds)
                    ->where('required_quantity', $from)
                    ->update([
                        'required_quantity' => $to,
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
