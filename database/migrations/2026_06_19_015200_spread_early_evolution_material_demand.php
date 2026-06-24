<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIALS = [
        'MAT_COMMON_GOBLIN_FANG' => '小鬼の牙',
        'MAT_COMMON_OLD_BONE' => '古びた骨片',
        'MAT_COMMON_ROTTEN_CLOTH' => '腐布',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->ensureMaterials();
            $this->spreadWeaponDemand();
            $this->spreadArmorDemand();
        });
    }

    public function down(): void
    {
        // Master-data balance migration only. Previous concentrated recipes are not restored.
    }

    private function ensureMaterials(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        foreach (array_keys(self::MATERIALS) as $code) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                array_merge(NormalDropMaterialConsolidator::payload($code), [
                    'city_id' => null,
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }

    private function spreadWeaponDemand(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach ([
            ['EVOL_0011', 'MAT_COMMON_MAGIC_ORE', 'MAT_COMMON_GOBLIN_FANG', 5],
            ['EVOL_0041', 'MAT_COMMON_BEAST_FANG', 'MAT_COMMON_GOBLIN_FANG', 5],
            ['EVOL_0081', 'MAT_COMMON_BEAST_FANG', 'MAT_COMMON_GOBLIN_FANG', 5],
            ['EVOL_0061', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 5],
            ['EVOL_0071', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_OLD_BONE', 5],
            ['EVOL_0002', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_OLD_BONE', 3],
            ['EVOL_0012', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_GOBLIN_FANG', 3],
            ['EVOL_0042', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_GOBLIN_FANG', 3],
            ['EVOL_0062', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 8],
            ['EVOL_0072', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_OLD_BONE', 8],
            ['EVOL_0082', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_GOBLIN_FANG', 3],
        ] as [$recipeId, $oldCode, $newCode, $quantity]) {
            $this->replaceWeaponIngredient($recipeId, $oldCode, $newCode, self::MATERIALS[$newCode], $quantity);
        }
    }

    private function spreadArmorDemand(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        foreach ([
            ['7011', 'MAT_COMMON_MONSTER_SHELL', 'MAT_COMMON_OLD_BONE', 5],
            ['7021', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 5],
            ['7041', 'MAT_COMMON_BEAST_FANG', 'MAT_COMMON_GOBLIN_FANG', 5],
            ['7051', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 5],
            ['7071', 'MAT_COMMON_DARK_CRYSTAL', 'MAT_COMMON_ROTTEN_CLOTH', 5],
            ['7012', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_OLD_BONE', 3],
            ['7022', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 8],
            ['7032', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 3],
            ['7042', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_GOBLIN_FANG', 3],
            ['7052', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 8],
            ['7072', 'MAT_REGION_ARKREA_RAW', 'MAT_COMMON_ROTTEN_CLOTH', 3],
        ] as [$recipeId, $oldCode, $newCode, $quantity]) {
            $this->replaceArmorIngredient($recipeId, $oldCode, $newCode, self::MATERIALS[$newCode], $quantity);
        }
    }

    private function replaceWeaponIngredient(string $recipeId, string $oldCode, string $newCode, string $newName, int $quantity): void
    {
        if (!DB::table('weapon_evolution_recipes')->where('recipe_id', $recipeId)->exists()) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $recipeId)
            ->where('ingredient_id', $newCode)
            ->where('ingredient_id', '<>', $oldCode)
            ->delete();

        $updated = DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $recipeId)
            ->where('ingredient_id', $oldCode)
            ->update([
                'ingredient_type' => 'material',
                'ingredient_id' => $newCode,
                'ingredient_name' => $newName,
                'quantity' => $quantity,
                'resolve_rule' => 'common_drop_material',
                'is_consumed' => true,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')->insert([
            'recipe_id' => $recipeId,
            'ingredient_type' => 'material',
            'ingredient_id' => $newCode,
            'ingredient_name' => $newName,
            'quantity' => $quantity,
            'resolve_rule' => 'common_drop_material',
            'is_consumed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function replaceArmorIngredient(string $recipeId, string $oldCode, string $newCode, string $newName, int $quantity): void
    {
        if (!DB::table('armor_evolution_recipes')->where('evolution_recipe_id', $recipeId)->exists()) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')
            ->where('evolution_recipe_id', $recipeId)
            ->where('material_id', $newCode)
            ->where('material_id', '<>', $oldCode)
            ->delete();

        $updated = DB::table('armor_evolution_recipe_ingredients')
            ->where('evolution_recipe_id', $recipeId)
            ->where('material_id', $oldCode)
            ->update([
                'ingredient_type' => 'specific_material',
                'material_id' => $newCode,
                'material_name' => $newName,
                'required_quantity' => $quantity,
                'resolve_rule' => 'common_drop_material',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')->insert([
            'ingredient_id' => 'EARLY_' . $recipeId . '_' . $newCode,
            'evolution_recipe_id' => $recipeId,
            'ingredient_type' => 'specific_material',
            'material_id' => $newCode,
            'material_name' => $newName,
            'required_quantity' => $quantity,
            'resolve_rule' => 'common_drop_material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
