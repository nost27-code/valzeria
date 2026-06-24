<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $this->ensureAccessoryCityMaterials($now);
        $this->relaxWeaponRecipes($now);
        $this->relaxArmorRecipes($now);
        $this->relaxAccessoryRecipes($now);
    }

    public function down(): void
    {
        // Master-data relaxation is intentionally not reverted automatically.
    }

    private function relaxWeaponRecipes($now): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('weapon_evolution_recipes')->where('is_active', true)->update([
            'same_weapon_count' => 1,
            'updated_at' => $now,
        ]);

        DB::table('weapon_evolution_recipe_ingredients')->delete();

        $recipes = DB::table('weapon_evolution_recipes')->where('is_active', true)->orderBy('id')->get();
        foreach ($recipes as $recipe) {
            foreach ($this->weaponRecipeMaterials((string) $recipe->from_rank, (string) $recipe->category_id) as $material) {
                DB::table('weapon_evolution_recipe_ingredients')->insert([
                    'recipe_id' => $recipe->recipe_id,
                    'ingredient_type' => 'material',
                    'ingredient_id' => $material['code'],
                    'ingredient_name' => $material['name'],
                    'quantity' => $material['quantity'],
                    'resolve_rule' => $material['resolve_rule'] ?? 'fixed',
                    'is_consumed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function weaponRecipeMaterials(string $rank, string $categoryId): array
    {
        $category = $this->weaponCategoryMaterials($categoryId);

        return match ($rank) {
            'G' => [
                $this->mat('WEV0001', '武器の欠片', 5),
            ],
            'F' => [
                $this->mat('WEV0001', '武器の欠片', 10),
            ],
            'E' => [
                $this->mat('WEV0001', '武器の欠片', 20),
            ],
            'D' => [
                $this->mat('WEV0002', '武具の結晶', 3),
            ],
            'C' => [
                $this->mat('WEV0002', '武具の結晶', 6),
                $this->mat($category['crystal'][0], $category['crystal'][1], 3, 'by_weapon_category'),
                $this->mat('TOKEN_CITY_MATERIAL', '都市素材', 3, 'city_token'),
            ],
            'B' => [
                $this->mat('WEV0002', '武具の結晶', 10),
                $this->mat($category['crystal'][0], $category['crystal'][1], 6, 'by_weapon_category'),
                $this->mat('TOKEN_CITY_MATERIAL', '都市素材', 5, 'city_token'),
            ],
            'A' => [
                $this->mat('WEV0003', '武具の核', 3),
                $this->mat($category['crystal'][0], $category['crystal'][1], 10, 'by_weapon_category'),
                $this->mat('TOKEN_CITY_HIGH_MATERIAL', '都市高位素材', 10, 'city_high_token'),
            ],
            'S' => [
                $this->mat('WEV0004', '古代武具片', 5),
                $this->mat($category['core'][0], $category['core'][1], 3, 'by_weapon_category'),
            ],
            'SS' => [
                $this->mat('WEV0005', '星屑の鍛材', 5),
                $this->mat($category['core'][0], $category['core'][1], 5, 'by_weapon_category'),
            ],
            'SSS' => [
                $this->mat('WEV0006', '秘境の星砂', 10),
                $this->mat($category['core'][0], $category['core'][1], 10, 'by_weapon_category'),
                $this->mat('WEV0007', '伝説の武具紋章', 1),
            ],
            default => [],
        };
    }

    private function weaponCategoryMaterials(string $categoryId): array
    {
        return match ($categoryId) {
            'PIERCE' => ['fragment' => ['WEV0011', '刺突の欠片'], 'crystal' => ['WEV0012', '刺突の結晶'], 'core' => ['WEV0013', '刺突の核']],
            'BLUNT' => ['fragment' => ['WEV0014', '打撃の欠片'], 'crystal' => ['WEV0015', '打撃の結晶'], 'core' => ['WEV0016', '打撃の核']],
            'RANGED' => ['fragment' => ['WEV0017', '射撃の欠片'], 'crystal' => ['WEV0018', '射撃の結晶'], 'core' => ['WEV0019', '射撃の核']],
            'MAGIC' => ['fragment' => ['WEV0020', '魔導の欠片'], 'crystal' => ['WEV0021', '魔導の結晶'], 'core' => ['WEV0022', '魔導の核']],
            default => ['fragment' => ['WEV0008', '斬撃の欠片'], 'crystal' => ['WEV0009', '斬撃の結晶'], 'core' => ['WEV0010', '斬撃の核']],
        };
    }

    private function relaxArmorRecipes($now): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('armor_evolution_recipes')->where('is_active', true)->update([
            'required_same_armor_count' => 1,
            'updated_at' => $now,
        ]);

        DB::table('armor_evolution_recipe_ingredients')->delete();

        $ingredientId = 1;
        $recipes = DB::table('armor_evolution_recipes')->where('is_active', true)->orderBy('id')->get();
        foreach ($recipes as $recipe) {
            foreach ($this->armorRecipeMaterials((string) $recipe->from_rank, (string) $recipe->armor_family_id) as $material) {
                DB::table('armor_evolution_recipe_ingredients')->insert([
                    'ingredient_id' => 'RELAXED_' . $ingredientId++,
                    'evolution_recipe_id' => $recipe->evolution_recipe_id,
                    'ingredient_type' => $material['type'] ?? 'specific_material',
                    'material_id' => $material['code'],
                    'material_name' => $material['name'],
                    'required_quantity' => $material['quantity'],
                    'resolve_rule' => $material['resolve_rule'] ?? 'material_idをそのまま使用',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function armorRecipeMaterials(string $rank, string $familyId): array
    {
        $category = $this->armorCategoryMaterials($familyId);

        return match ($rank) {
            'G' => [
                $this->mat('5001', '防具の欠片', 5),
            ],
            'F' => [
                $this->mat('5001', '防具の欠片', 10),
            ],
            'E' => [
                $this->mat('5001', '防具の欠片', 20),
            ],
            'D' => [
                $this->mat('5002', '防具の結晶', 3),
            ],
            'C' => [
                $this->mat('5002', '防具の結晶', 6),
                $this->mat($category['crystal'][0], $category['crystal'][1], 3, 'material_idをそのまま使用', 'category_mid'),
                $this->mat('5051', '都市素材（進化対象街）', 3, 'unlock_city_idや現在街に応じて具体素材へ解決', 'abstract_resolved_material'),
            ],
            'B' => [
                $this->mat('5002', '防具の結晶', 10),
                $this->mat($category['crystal'][0], $category['crystal'][1], 6, 'material_idをそのまま使用', 'category_mid'),
                $this->mat('5051', '都市素材（進化対象街）', 5, 'unlock_city_idや現在街に応じて具体素材へ解決', 'abstract_resolved_material'),
            ],
            'A' => [
                $this->mat('5003', '防具の核', 3),
                $this->mat($category['crystal'][0], $category['crystal'][1], 10, 'material_idをそのまま使用', 'category_mid'),
                $this->mat('5052', '都市高位素材（進化対象街）', 10, 'unlock_city_idや現在街に応じて具体素材へ解決', 'abstract_resolved_material'),
            ],
            'S' => [
                $this->mat('5004', '古代防具片', 5),
                $this->mat($category['core'][0], $category['core'][1], 3, 'material_idをそのまま使用', 'category_high'),
            ],
            'SS' => [
                $this->mat('5005', '星屑の縫材', 5),
                $this->mat($category['core'][0], $category['core'][1], 5, 'material_idをそのまま使用', 'category_high'),
            ],
            'SSS' => [
                $this->mat('5050', '秘境の守護繊維', 10),
                $this->mat($category['core'][0], $category['core'][1], 10, 'material_idをそのまま使用', 'category_high'),
                $this->mat('5006', '伝説の縫魂', 1),
            ],
            default => [],
        };
    }

    private function armorCategoryMaterials(string $familyId): array
    {
        return match ($familyId) {
            'heavy_armor' => ['fragment' => ['5013', '重装の欠片'], 'crystal' => ['5014', '重装の結晶'], 'core' => ['5015', '重装の核']],
            'robe', 'arcane_armor' => ['fragment' => ['5016', '魔布の欠片'], 'crystal' => ['5017', '魔布の結晶'], 'core' => ['5018', '魔布の核']],
            'holy_vestment' => ['fragment' => ['5019', '聖布の欠片'], 'crystal' => ['5020', '聖布の結晶'], 'core' => ['5021', '聖布の核']],
            'martial_garb' => ['fragment' => ['5022', '闘具の欠片'], 'crystal' => ['5023', '闘具の結晶'], 'core' => ['5024', '闘具の核']],
            default => ['fragment' => ['5010', '軽装の欠片'], 'crystal' => ['5011', '軽装の結晶'], 'core' => ['5012', '軽装の核']],
        };
    }

    private function relaxAccessoryRecipes($now): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('accessory_evolution_recipes')->where('is_active', true)->update([
            'required_same_accessory_count' => 1,
            'updated_at' => $now,
        ]);

        DB::table('accessory_evolution_recipe_ingredients')->delete();

        $recipes = DB::table('accessory_evolution_recipes')->where('is_active', true)->orderBy('id')->get();
        foreach ($recipes as $recipe) {
            $source = DB::table('items')->where('external_item_id', $recipe->from_accessory_id)->first();
            $category = $this->accessoryCategoryMaterials((string) ($source?->accessory_category_id ?? ''));

            foreach ($this->accessoryRecipeMaterials((string) $recipe->from_rank, $category) as $material) {
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

    private function accessoryRecipeMaterials(string $rank, array $category): array
    {
        return match ($rank) {
            'G' => [
                $this->mat('ACC0001', '装飾の欠片', 4),
                $this->mat($category['fragment'][0], $category['fragment'][1], 2),
            ],
            'F' => [
                $this->mat('ACC0001', '装飾の欠片', 8),
                $this->mat($category['fragment'][0], $category['fragment'][1], 5),
            ],
            'E' => [
                $this->mat('ACC0001', '装飾の欠片', 16),
                $this->mat($category['fragment'][0], $category['fragment'][1], 10),
            ],
            'D' => [
                $this->mat('ACC0002', '装飾の結晶', 2),
                $this->mat($category['fragment'][0], $category['fragment'][1], 16),
            ],
            'C' => [
                $this->mat('ACC0002', '装飾の結晶', 5),
                $this->mat($category['crystal'][0], $category['crystal'][1], 2),
                $this->mat('ACC_CITY_MATERIAL', '装飾都市素材', 2),
            ],
            'B' => [
                $this->mat('ACC0002', '装飾の結晶', 8),
                $this->mat($category['crystal'][0], $category['crystal'][1], 5),
                $this->mat('ACC_CITY_MATERIAL', '装飾都市素材', 3),
            ],
            'A' => [
                $this->mat('ACC0003', '装飾の核', 3),
                $this->mat($category['crystal'][0], $category['crystal'][1], 8),
                $this->mat('ACC_CITY_HIGH_MATERIAL', '装飾都市高位素材', 5),
            ],
            'S' => [
                $this->mat('ACC0004', '古代装飾片', 5),
                $this->mat($category['core'][0], $category['core'][1], 3),
            ],
            'SS' => [
                $this->mat('ACC0005', '星屑の宝材', 5),
                $this->mat($category['core'][0], $category['core'][1], 5),
            ],
            'SSS' => [
                $this->mat('ACC0006', '秘境素材の欠片', 5),
                $this->mat($category['core'][0], $category['core'][1], 8),
                $this->mat('ACC0005', '星屑の宝材', 1),
            ],
            default => [],
        };
    }

    private function accessoryCategoryMaterials(string $categoryId): array
    {
        $fragment = DB::table('materials')
            ->where('material_type', 'accessory_evolution')
            ->where('category_id', $categoryId)
            ->where('name', 'like', '%の欠片')
            ->first();
        $crystal = DB::table('materials')
            ->where('material_type', 'accessory_evolution')
            ->where('category_id', $categoryId)
            ->where('name', 'like', '%の結晶')
            ->first();
        $core = DB::table('materials')
            ->where('material_type', 'accessory_evolution')
            ->where('category_id', $categoryId)
            ->where('name', 'like', '%の核')
            ->first();

        return [
            'fragment' => [(string) ($fragment->material_code ?? 'ACC0010'), (string) ($fragment->name ?? '腕力の欠片')],
            'crystal' => [(string) ($crystal->material_code ?? 'ACC0011'), (string) ($crystal->name ?? '腕力の結晶')],
            'core' => [(string) ($core->material_code ?? 'ACC0012'), (string) ($core->name ?? '腕力の核')],
        ];
    }

    private function ensureAccessoryCityMaterials($now): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        for ($cityId = 1; $cityId <= 10; $cityId++) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => 'ACC_CITY_' . str_pad((string) $cityId, 2, '0', STR_PAD_LEFT)],
                [
                    'name' => '装飾都市素材' . $cityId,
                    'category' => 'accessory_city',
                    'rarity' => 'R',
                    'material_type' => 'accessory_city',
                    'category_id' => 'city',
                    'rank_tier' => 2,
                    'is_consumable' => true,
                    'is_tradable' => false,
                    'city_id' => $cityId,
                    'main_use' => '装飾品進化',
                    'obtain_method' => '装飾品進化用の都市素材。',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            DB::table('materials')->updateOrInsert(
                ['material_code' => 'ACC_CITY_HIGH_' . str_pad((string) $cityId, 2, '0', STR_PAD_LEFT)],
                [
                    'name' => '装飾都市高位素材' . $cityId,
                    'category' => 'accessory_city_high',
                    'rarity' => 'SR',
                    'material_type' => 'accessory_city_high',
                    'category_id' => 'city_high',
                    'rank_tier' => 3,
                    'is_consumable' => true,
                    'is_tradable' => false,
                    'city_id' => $cityId,
                    'main_use' => '装飾品進化',
                    'obtain_method' => '装飾品進化用の都市高位素材。',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function mat(string $code, string $name, int $quantity, string $resolveRule = 'fixed', string $type = 'specific_material'): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'quantity' => $quantity,
            'resolve_rule' => $resolveRule,
            'type' => $type,
        ];
    }
};
