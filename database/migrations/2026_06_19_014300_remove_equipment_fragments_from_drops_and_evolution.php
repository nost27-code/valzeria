<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FRAGMENT_CODES = [
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    private const FRAGMENT_NAMES = [
        '装備の欠片',
        '上質な装備の欠片',
        '強装備の欠片',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->removeMaterialDrops();
            $this->removeEvolutionIngredients();
        });
    }

    public function down(): void
    {
        // Balance-data cleanup only. Removed fragment drops/recipe requirements are not restored.
    }

    private function removeMaterialDrops(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->whereIn('material_code', self::FRAGMENT_CODES)
            ->pluck('id');

        if ($materialIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('material_drops')) {
            DB::table('material_drops')
                ->whereIn('material_id', $materialIds)
                ->delete();
        }

        DB::table('materials')
            ->whereIn('id', $materialIds)
            ->update([
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'updated_at' => now(),
            ]);
    }

    private function removeEvolutionIngredients(): void
    {
        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('ingredient_id', self::FRAGMENT_CODES)
                ->orWhereIn('ingredient_name', self::FRAGMENT_NAMES)
                ->delete();
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('material_id', self::FRAGMENT_CODES)
                ->orWhereIn('material_name', self::FRAGMENT_NAMES)
                ->delete();
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->whereIn('material_code', self::FRAGMENT_CODES)
                ->orWhereIn('material_name', self::FRAGMENT_NAMES)
                ->delete();
        }
    }
};
