<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIGH_RANKS = ['A', 'S', 'SS', 'SSS'];

    public function up(): void
    {
        $this->disableAccessoryBranchRecipes();
        $this->restoreLinearAccessoryRecipes();
        $this->disableAccessoryBranchMaterialDrops();
        $this->markAccessoryBranchMaterialsAsLegacy();
    }

    public function down(): void
    {
        // Master-data correction only. Accessory branch evolution is intentionally not restored.
    }

    private function disableAccessoryBranchRecipes(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        $recipeIds = DB::table('accessory_evolution_recipes')
            ->where('recipe_id', 'like', 'BR_ACC_%')
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($recipeIds === []) {
            return;
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->whereIn('recipe_id', $recipeIds)
                ->delete();
        }

        DB::table('accessory_evolution_recipes')
            ->whereIn('recipe_id', $recipeIds)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    private function restoreLinearAccessoryRecipes(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        DB::table('accessory_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->where('recipe_id', 'not like', 'BR_%')
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

        if (
            !Schema::hasTable('items')
            || !Schema::hasColumn('items', 'is_evolution_enabled')
            || !Schema::hasColumn('items', 'external_item_id')
            || !Schema::hasColumn('items', 'next_accessory_external_id')
        ) {
            return;
        }

        DB::table('items')
            ->where('type', 'accessory')
            ->whereNotNull('next_accessory_external_id')
            ->where('external_item_id', 'not like', 'ACC_BR_%')
            ->update([
                'is_evolution_enabled' => true,
                'updated_at' => now(),
            ]);
    }

    private function disableAccessoryBranchMaterialDrops(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->where('material_code', 'like', 'MAT_BR_ACC_%')
            ->pluck('id')
            ->all();

        if ($materialIds === [] || !Schema::hasTable('material_drops')) {
            return;
        }

        DB::table('material_drops')
            ->whereIn('material_id', $materialIds)
            ->update([
                'is_active' => false,
                'drop_rate' => 0,
                'updated_at' => now(),
            ]);
    }

    private function markAccessoryBranchMaterialsAsLegacy(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $payload = [
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
            'main_use' => '廃止済み',
            'obtain_method' => '装飾品の分岐進化廃止により、現在は新規入手・使用しません。',
            'updated_at' => now(),
        ];

        foreach (['drop_rate', 'drop_first_clear_only', 'drop_timing', 'main_use', 'obtain_method'] as $column) {
            if (!Schema::hasColumn('materials', $column)) {
                unset($payload[$column]);
            }
        }

        DB::table('materials')
            ->where('material_code', 'like', 'MAT_BR_ACC_%')
            ->update($payload);
    }
};
