<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WEAPON_A_TO_S = [
        'HOLY' => [
            ['WEV0035', '海鳴りの蒼鉱', 2],
            ['MAT_COMMON_HOLY_FRAGMENT', '聖片', 6],
        ],
        'DARK' => [
            ['WEV0045', 'ルミナス魔晶', 2],
            ['MAT_COMMON_DARK_CRYSTAL', '黒結晶', 6],
        ],
        'GALE' => [
            ['5029', '精霊樹の繊維', 3],
            ['MAT_COMMON_WING_MEMBRANE', '薄い翼膜', 6],
        ],
    ];

    private const WEAPON_S_TO_SS = [
        'HOLY' => [
            ['5030', '精霊王の絹糸', 3],
        ],
        'DARK' => [
            ['5040', '深魔の黒布', 3],
        ],
        'GALE' => [
            ['5029', '精霊樹の繊維', 4],
        ],
    ];

    private const ARMOR_A_TO_S = [
        'BR_HEAVY_ARMOR' => [
            ['5028', '海守りの織布', 3],
            ['MAT_COMMON_DRAGON_SCALE', '竜鱗', 3],
        ],
        'BR_ARCANE_ARMOR' => [
            ['WEV0045', 'ルミナス魔晶', 2],
            ['MAT_COMMON_MAGIC_CRYSTAL', '魔力水晶', 6],
        ],
        'BR_LIGHT_ARMOR' => [
            ['5029', '精霊樹の繊維', 3],
            ['MAT_COMMON_WING_MEMBRANE', '薄い翼膜', 6],
        ],
        'BR_TRAVELER_ARMOR' => [
            ['5028', '海守りの織布', 3],
            ['5035', '砂金繊維', 3],
        ],
    ];

    private const ARMOR_S_TO_SS = [
        'BR_HEAVY_ARMOR' => [
            ['5034', '氷帝の守護布', 3],
            ['MAT_REGION_ICE_CRYSTAL', '氷晶片', 4],
        ],
        'BR_ARCANE_ARMOR' => [
            ['5037', '魔導繊維', 4],
        ],
        'BR_LIGHT_ARMOR' => [
            ['5030', '精霊王の絹糸', 3],
        ],
        'BR_TRAVELER_ARMOR' => [
            ['5036', '砂王の宝布', 3],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->removeOverbroadIceCrystalRequirement();
            $this->addWeaponFlavorMaterials();
            $this->addArmorFlavorMaterials();
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Added supplemental requirements are not restored.
    }

    private function removeOverbroadIceCrystalRequirement(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        $recipeIds = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->where('from_rank', 'A')
            ->where('evolution_recipe_id', 'like', 'BR_%')
            ->pluck('evolution_recipe_id');

        if ($recipeIds->isEmpty()) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('evolution_recipe_id', $recipeIds)
            ->where('material_id', 'MAT_REGION_ICE_CRYSTAL')
            ->delete();
    }

    private function addWeaponFlavorMaterials(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->where('recipe_id', 'like', 'BR_%')
            ->whereIn('from_rank', ['A', 'S'])
            ->get();

        foreach ($recipes as $recipe) {
            $series = $this->weaponSeries((string) $recipe->recipe_id);
            if ($series === null) {
                continue;
            }

            $materials = (string) $recipe->from_rank === 'A'
                ? (self::WEAPON_A_TO_S[$series] ?? [])
                : (self::WEAPON_S_TO_SS[$series] ?? []);

            foreach ($materials as [$code, $name, $quantity]) {
                $this->insertWeaponIngredient((string) $recipe->recipe_id, $code, $name, $quantity);
            }
        }
    }

    private function addArmorFlavorMaterials(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->where('evolution_recipe_id', 'like', 'BR_%')
            ->whereIn('from_rank', ['A', 'S'])
            ->get();

        foreach ($recipes as $recipe) {
            $family = (string) ($recipe->armor_family_id ?? '');
            $materials = (string) $recipe->from_rank === 'A'
                ? (self::ARMOR_A_TO_S[$family] ?? [])
                : (self::ARMOR_S_TO_SS[$family] ?? []);

            foreach ($materials as [$code, $name, $quantity]) {
                $this->insertArmorIngredient((string) $recipe->evolution_recipe_id, $code, $name, $quantity);
            }
        }
    }

    private function weaponSeries(string $recipeId): ?string
    {
        return match (true) {
            str_contains($recipeId, '_HOLY_') => 'HOLY',
            str_contains($recipeId, '_DARK_') => 'DARK',
            str_contains($recipeId, '_GALE_') => 'GALE',
            default => null,
        };
    }

    private function insertWeaponIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('ingredient_id', $code)->exists()) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')->insert([
            'recipe_id' => $recipeId,
            'ingredient_type' => 'material',
            'ingredient_id' => $code,
            'ingredient_name' => $name,
            'quantity' => $quantity,
            'resolve_rule' => 'branch_series_flavor_material',
            'is_consumed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertArmorIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->where('material_id', $code)->exists()) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')->insert([
            'ingredient_id' => 'SERIES_' . $recipeId . '_' . substr(md5($code), 0, 8),
            'evolution_recipe_id' => $recipeId,
            'ingredient_type' => 'specific_material',
            'material_id' => $code,
            'material_name' => $name,
            'required_quantity' => $quantity,
            'resolve_rule' => 'branch_series_flavor_material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
