<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIALS = [
        'MAT_REGION_TIDAL_PIECE' => '潮騒の素材片',
        'MAT_COMMON_NATURAL_FRAGMENT' => '自然片',
        'MAT_COMMON_FEATHER' => '羽根',
        'MAT_COMMON_THUNDER_STONE' => '雷石',
        'MAT_COMMON_FIRE_SEED' => '火種',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->ensureMaterials();
            $this->addWeaponDemand();
            $this->addArmorDemand();
            $this->addAccessoryDemand();
        });
    }

    public function down(): void
    {
        // Master-data balance migration only. Added supplemental recipe requirements are not restored.
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
                    'city_id' => str_starts_with($code, 'MAT_REGION_') ? 2 : null,
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

    private function addWeaponDemand(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach ([
            ['EVOL_0023', 'MAT_REGION_TIDAL_PIECE', 4],
            ['EVOL_0053', 'MAT_REGION_TIDAL_PIECE', 4],
            ['EVOL_0054', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['EVOL_0064', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['EVOL_0015', 'MAT_COMMON_FEATHER', 6],
            ['EVOL_0055', 'MAT_COMMON_FEATHER', 6],
            ['EVOL_0056', 'MAT_COMMON_FEATHER', 8],
            ['EVOL_0065', 'MAT_COMMON_THUNDER_STONE', 6],
            ['EVOL_0075', 'MAT_COMMON_THUNDER_STONE', 6],
            ['EVOL_0095', 'MAT_COMMON_THUNDER_STONE', 6],
            ['EVOL_0096', 'MAT_COMMON_THUNDER_STONE', 8],
            ['EVOL_0034', 'MAT_COMMON_FIRE_SEED', 5],
            ['EVOL_0044', 'MAT_COMMON_FIRE_SEED', 5],
            ['EVOL_0035', 'MAT_COMMON_FIRE_SEED', 6],
            ['EVOL_0045', 'MAT_COMMON_FIRE_SEED', 6],
        ] as [$recipeId, $code, $quantity]) {
            $this->insertWeaponIngredient($recipeId, $code, self::MATERIALS[$code], $quantity);
        }
    }

    private function addArmorDemand(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        foreach ([
            ['7003', 'MAT_REGION_TIDAL_PIECE', 4],
            ['7033', 'MAT_REGION_TIDAL_PIECE', 4],
            ['7024', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['7034', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['7005', 'MAT_COMMON_FEATHER', 6],
            ['7075', 'MAT_COMMON_FEATHER', 6],
            ['7006', 'MAT_COMMON_FEATHER', 8],
            ['7055', 'MAT_COMMON_THUNDER_STONE', 6],
            ['7056', 'MAT_COMMON_THUNDER_STONE', 8],
            ['7015', 'MAT_COMMON_FIRE_SEED', 6],
            ['7045', 'MAT_COMMON_FIRE_SEED', 6],
            ['7016', 'MAT_COMMON_FIRE_SEED', 8],
            ['7046', 'MAT_COMMON_FIRE_SEED', 8],
        ] as [$recipeId, $code, $quantity]) {
            $this->insertArmorIngredient($recipeId, $code, self::MATERIALS[$code], $quantity);
        }
    }

    private function addAccessoryDemand(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        foreach ([
            ['ACC_EVO_WIND_CHARM_E_TO_D', 'MAT_REGION_TIDAL_PIECE', 4],
            ['ACC_EVO_LUCK_CHARM_E_TO_D', 'MAT_REGION_TIDAL_PIECE', 4],
            ['ACC_EVO_LIFE_NECKLACE_D_TO_C', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['ACC_EVO_LUCK_CHARM_D_TO_C', 'MAT_COMMON_NATURAL_FRAGMENT', 5],
            ['ACC_EVO_WIND_CHARM_C_TO_B', 'MAT_COMMON_FEATHER', 6],
            ['ACC_EVO_WIND_CHARM_B_TO_A', 'MAT_COMMON_FEATHER', 8],
            ['ACC_EVO_MAGIC_RING_C_TO_B', 'MAT_COMMON_THUNDER_STONE', 6],
            ['ACC_EVO_MIND_EARRING_C_TO_B', 'MAT_COMMON_THUNDER_STONE', 6],
            ['ACC_EVO_MAGIC_RING_B_TO_A', 'MAT_COMMON_THUNDER_STONE', 8],
            ['ACC_EVO_POWER_RING_C_TO_B', 'MAT_COMMON_FIRE_SEED', 6],
            ['ACC_EVO_POWER_RING_B_TO_A', 'MAT_COMMON_FIRE_SEED', 8],
        ] as [$recipeId, $code, $quantity]) {
            $this->insertAccessoryIngredient($recipeId, $code, self::MATERIALS[$code], $quantity);
        }
    }

    private function insertWeaponIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (!DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('ingredient_id', $code)->exists()) {
            DB::table('weapon_evolution_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'ingredient_type' => 'material',
                'ingredient_id' => $code,
                'ingredient_name' => $name,
                'quantity' => $quantity,
                'resolve_rule' => 'common_drop_material',
                'is_consumed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertArmorIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (!DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->where('material_id', $code)->exists()) {
            DB::table('armor_evolution_recipe_ingredients')->insert([
                'ingredient_id' => 'AREA_' . $recipeId . '_' . substr(md5($code), 0, 8),
                'evolution_recipe_id' => $recipeId,
                'ingredient_type' => 'specific_material',
                'material_id' => $code,
                'material_name' => $name,
                'required_quantity' => $quantity,
                'resolve_rule' => 'common_drop_material',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertAccessoryIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (!DB::table('accessory_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('material_code', $code)->exists()) {
            DB::table('accessory_evolution_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'ingredient_type' => 'material',
                'material_code' => $code,
                'material_name' => $name,
                'required_quantity' => $quantity,
                'is_consumed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
