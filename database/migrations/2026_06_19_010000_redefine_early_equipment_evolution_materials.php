<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EARLY_RANKS = ['G', 'F', 'E', 'D', 'C', 'B'];

    public function up(): void
    {
        $now = now();

        $this->redefineWeaponRecipes($now);
        $this->redefineArmorRecipes($now);
        $this->redefineAccessoryRecipes($now);
    }

    public function down(): void
    {
        // Master-data redefinition only. Old fragment-heavy recipes are not restored automatically.
    }

    private function redefineWeaponRecipes($now): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', self::EARLY_RANKS)
            ->where('recipe_id', 'not like', 'BR_%')
            ->orderBy('id')
            ->get();

        $recipeIds = $recipes->pluck('recipe_id')->all();
        if ($recipeIds === []) {
            return;
        }

        DB::table('weapon_evolution_recipes')->whereIn('recipe_id', $recipeIds)->update([
            'same_weapon_count' => 1,
            'updated_at' => $now,
        ]);
        DB::table('weapon_evolution_recipe_ingredients')->whereIn('recipe_id', $recipeIds)->delete();

        foreach ($recipes as $recipe) {
            foreach ($this->weaponMaterials((string) $recipe->from_rank, (string) ($recipe->category_id ?? ''), $this->recipeCityId($recipe)) as $material) {
                DB::table('weapon_evolution_recipe_ingredients')->insert([
                    'recipe_id' => $recipe->recipe_id,
                    'ingredient_type' => 'material',
                    'ingredient_id' => $material['code'],
                    'ingredient_name' => $material['name'],
                    'quantity' => $material['quantity'],
                    'resolve_rule' => 'common_drop_material',
                    'is_consumed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function redefineArmorRecipes($now): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', self::EARLY_RANKS)
            ->where('evolution_recipe_id', 'not like', 'BR_%')
            ->orderBy('id')
            ->get();

        $recipeIds = $recipes->pluck('evolution_recipe_id')->all();
        if ($recipeIds === []) {
            return;
        }

        DB::table('armor_evolution_recipes')->whereIn('evolution_recipe_id', $recipeIds)->update([
            'required_same_armor_count' => 1,
            'updated_at' => $now,
        ]);
        DB::table('armor_evolution_recipe_ingredients')->whereIn('evolution_recipe_id', $recipeIds)->delete();

        $ingredientId = 1;
        foreach ($recipes as $recipe) {
            foreach ($this->armorMaterials((string) $recipe->from_rank, (string) ($recipe->armor_family_id ?? ''), $this->recipeCityId($recipe)) as $material) {
                DB::table('armor_evolution_recipe_ingredients')->insert([
                    'ingredient_id' => 'COMMON_' . $recipe->evolution_recipe_id . '_' . $ingredientId++,
                    'evolution_recipe_id' => $recipe->evolution_recipe_id,
                    'ingredient_type' => 'specific_material',
                    'material_id' => $material['code'],
                    'material_name' => $material['name'],
                    'required_quantity' => $material['quantity'],
                    'resolve_rule' => 'common_drop_material',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function redefineAccessoryRecipes($now): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('accessory_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', self::EARLY_RANKS)
            ->where('recipe_id', 'not like', 'BR_%')
            ->orderBy('id')
            ->get();

        $recipeIds = $recipes->pluck('recipe_id')->all();
        if ($recipeIds === []) {
            return;
        }

        DB::table('accessory_evolution_recipes')->whereIn('recipe_id', $recipeIds)->update([
            'required_same_accessory_count' => 1,
            'updated_at' => $now,
        ]);
        DB::table('accessory_evolution_recipe_ingredients')->whereIn('recipe_id', $recipeIds)->delete();

        foreach ($recipes as $recipe) {
            $source = DB::table('items')->where('external_item_id', $recipe->from_accessory_id)->first();
            foreach ($this->accessoryMaterials((string) $recipe->from_rank, (string) ($source?->accessory_category_id ?? ''), $this->recipeCityId($recipe)) as $material) {
                DB::table('accessory_evolution_recipe_ingredients')->insert([
                    'recipe_id' => $recipe->recipe_id,
                    'ingredient_type' => 'material',
                    'material_code' => $material['code'],
                    'material_name' => $material['name'],
                    'required_quantity' => $material['quantity'],
                    'is_consumed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function weaponMaterials(string $rank, string $categoryId, int $cityId): array
    {
        if ($categoryId === 'MAGIC') {
            return $this->magicRankMaterials($rank, $cityId);
        }

        [$mainCode, $mainName, $subCode, $subName] = match ($categoryId) {
            'RANGED' => ['MAT_COMMON_WING_MEMBRANE', '薄い翼膜', 'MAT_COMMON_BEAST_FUR', '獣の毛皮'],
            'BLUNT' => ['MAT_COMMON_BEAST_FANG', '獣牙', 'MAT_COMMON_MONSTER_SHELL', '魔物の外殻'],
            'PIERCE' => ['MAT_COMMON_MAGIC_ORE', '魔鉱片', 'MAT_COMMON_BEAST_FANG', '獣牙'],
            default => ['MAT_COMMON_MAGIC_ORE', '魔鉱片', 'MAT_COMMON_OLD_BADGE', '古びた徽章'],
        };

        return $this->rankMaterials($rank, $mainCode, $mainName, $subCode, $subName, $cityId);
    }

    private function armorMaterials(string $rank, string $familyId, int $cityId): array
    {
        if (in_array($familyId, ['robe', 'arcane_armor'], true) && in_array($rank, ['G', 'F'], true)) {
            $regional = $this->regionalMaterial($cityId);

            return match ($rank) {
                'G' => [$this->mat($regional[0], $regional[1], 5)],
                'F' => [$this->mat($regional[0], $regional[1], 8)],
            };
        }

        [$mainCode, $mainName, $subCode, $subName] = match ($familyId) {
            'heavy_armor' => ['MAT_COMMON_MONSTER_SHELL', '魔物の外殻', 'MAT_COMMON_MAGIC_ORE', '魔鉱片'],
            'robe', 'arcane_armor' => ['MAT_COMMON_FAIRY_DUST', '妖精粉', 'MAT_COMMON_MONSTER_CORE', '魔物の魔核'],
            'holy_vestment' => ['MAT_COMMON_HOLY_FRAGMENT', '聖片', 'MAT_COMMON_FAIRY_DUST', '妖精粉'],
            'martial_garb' => ['MAT_COMMON_BEAST_FANG', '獣牙', 'MAT_COMMON_BEAST_FUR', '獣の毛皮'],
            'shadow_garb' => ['MAT_COMMON_DARK_CRYSTAL', '黒結晶', 'MAT_COMMON_WING_MEMBRANE', '薄い翼膜'],
            'traveler_wear' => ['MAT_COMMON_OLD_BADGE', '古びた徽章', 'MAT_COMMON_BEAST_FUR', '獣の毛皮'],
            default => ['MAT_COMMON_BEAST_FUR', '獣の毛皮', 'MAT_COMMON_WING_MEMBRANE', '薄い翼膜'],
        };

        return $this->rankMaterials($rank, $mainCode, $mainName, $subCode, $subName, $cityId);
    }

    private function accessoryMaterials(string $rank, string $categoryId, int $cityId): array
    {
        if (in_array($categoryId, ['magic', 'mind'], true)) {
            return $this->magicRankMaterials($rank, $cityId);
        }

        [$mainCode, $mainName, $subCode, $subName] = match ($categoryId) {
            'power' => ['MAT_COMMON_BEAST_FANG', '獣牙', 'MAT_COMMON_MAGIC_ORE', '魔鉱片'],
            'guard', 'life' => ['MAT_COMMON_MONSTER_SHELL', '魔物の外殻', 'MAT_COMMON_HOLY_FRAGMENT', '聖片'],
            'prayer' => ['MAT_COMMON_HOLY_FRAGMENT', '聖片', 'MAT_COMMON_FAIRY_DUST', '妖精粉'],
            'wind' => ['MAT_COMMON_WING_MEMBRANE', '薄い翼膜', 'MAT_COMMON_BEAST_FUR', '獣の毛皮'],
            'luck' => ['MAT_COMMON_FAIRY_DUST', '妖精粉', 'MAT_COMMON_DARK_CRYSTAL', '黒結晶'],
            default => ['MAT_COMMON_OLD_BADGE', '古びた徽章', 'MAT_COMMON_MONSTER_FRAGMENT', '魔物の欠片'],
        };

        return $this->rankMaterials($rank, $mainCode, $mainName, $subCode, $subName, $cityId);
    }

    private function magicRankMaterials(string $rank, int $cityId): array
    {
        $regional = $this->regionalMaterialForRank($rank, $cityId);

        return match ($rank) {
            'G' => [$this->mat($regional[0], $regional[1], 5)],
            'F' => [$this->mat($regional[0], $regional[1], 8)],
            'E' => [$this->mat('MAT_COMMON_FAIRY_DUST', '妖精粉', 12), $this->mat($regional[0], $regional[1], 5)],
            'D' => [$this->mat('MAT_COMMON_MONSTER_CORE', '魔物の魔核', 18), $this->mat($regional[0], $regional[1], 8), $this->mat('MAT_COMMON_FAIRY_DUST', '妖精粉', 4)],
            'C' => [$this->mat('MAT_COMMON_MONSTER_CORE', '魔物の魔核', 24), $this->mat($regional[0], $regional[1], 10), $this->mat('MAT_COMMON_FAIRY_DUST', '妖精粉', 6)],
            'B' => [$this->mat('MAT_COMMON_MONSTER_CORE', '魔物の魔核', 36), $this->mat($regional[0], $regional[1], 14), $this->mat('MAT_COMMON_FAIRY_DUST', '妖精粉', 10)],
            default => [],
        };
    }

    private function rankMaterials(string $rank, string $mainCode, string $mainName, string $subCode, string $subName, int $cityId): array
    {
        $regional = $this->regionalMaterialForRank($rank, $cityId);

        return match ($rank) {
            'G' => [$this->mat($mainCode, $mainName, 5)],
            'F' => [$this->mat($mainCode, $mainName, 8), $this->mat($regional[0], $regional[1], 3)],
            'E' => [$this->mat($mainCode, $mainName, 12), $this->mat($regional[0], $regional[1], 5)],
            'D' => [$this->mat($mainCode, $mainName, 18), $this->mat($regional[0], $regional[1], 8), $this->mat($subCode, $subName, 4)],
            'C' => [$this->mat($mainCode, $mainName, 24), $this->mat($regional[0], $regional[1], 10), $this->mat($subCode, $subName, 6)],
            'B' => [$this->mat($mainCode, $mainName, 36), $this->mat($regional[0], $regional[1], 14), $this->mat($subCode, $subName, 10)],
            default => [],
        };
    }

    private function regionalMaterial(int $cityId): array
    {
        return match ($cityId) {
            2 => ['MAT_REGION_TIDAL_PIECE', '潮騒の素材片'],
            3 => ['MAT_REGION_WORLD_TREE_LEAF', '世界樹の葉片'],
            4 => ['MAT_REGION_BLACK_IRON_PART', '黒鉄の部材'],
            5 => ['MAT_REGION_ICE_CRYSTAL', '氷晶片'],
            6 => ['MAT_REGION_ANCIENT_SAND', '古代砂晶'],
            7 => ['MAT_REGION_MAGIC_CRYSTAL', '魔導結晶'],
            8 => ['MAT_REGION_ABYSS_FRAGMENT', '深淵の欠片'],
            9, 10 => ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根'],
            default => ['MAT_REGION_ARKREA_RAW', 'アークレアの粗素材'],
        };
    }

    private function regionalMaterialForRank(string $rank, int $cityId): array
    {
        return match ($rank) {
            'D', 'C' => ['MAT_REGION_WORLD_TREE_LEAF', '世界樹の葉片'],
            'B' => ['MAT_REGION_BLACK_IRON_PART', '黒鉄の部材'],
            default => $this->regionalMaterial($cityId),
        };
    }

    private function recipeCityId(object $recipe): int
    {
        $cityId = (int) ($recipe->unlock_city_id ?? 0);
        if ($cityId >= 1 && $cityId <= 10) {
            return $cityId;
        }

        return 1;
    }

    private function mat(string $code, string $name, int $quantity): array
    {
        return compact('code', 'name', 'quantity');
    }
};
