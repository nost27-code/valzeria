<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $data = $this->loadMaster();
        $now = now();

        $this->importMaterials($data['materials'] ?? [], $now);
        $this->importItems($data['items'] ?? [], $now);
        $this->importRecipes($data['recipes'] ?? [], $data['ingredients'] ?? [], $now);
    }

    public function down(): void
    {
        // Branch equipment is master data. Do not remove player-owned evolved items automatically.
    }

    private function loadMaster(): array
    {
        $path = database_path('data/equipment_branch_evolution_master.json');
        if (!is_file($path)) {
            throw new RuntimeException('equipment_branch_evolution_master.json not found.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importMaterials(array $rows, $now): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        foreach ($rows as $row) {
            $payload = [
                'name' => $row['name'],
                'category' => $row['category'] ?? '分岐進化素材',
                'rarity' => $row['rarity'] ?? 'SR',
                'element' => null,
                'main_use' => $row['main_use'] ?? '装備進化',
                'npc_sale_price' => 0,
                'is_tradable' => (bool) ($row['is_tradable'] ?? false),
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'updated_at' => $now,
            ];

            foreach ([
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => $row['material_type'] ?? 'branch_evolution',
                'category_id' => $row['category_id'] ?? null,
                'rank_tier' => (int) ($row['rank_tier'] ?? 3),
                'is_consumable' => (bool) ($row['is_consumable'] ?? true),
                'obtain_method' => $row['obtain_method'] ?? '分岐進化用の周回素材。',
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            if (!DB::table('materials')->where('material_code', $row['material_code'])->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(
                ['material_code' => $row['material_code']],
                $payload
            );
        }
    }

    private function importItems(array $rows, $now): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        foreach ($rows as $row) {
            $externalId = (string) $row['external_item_id'];
            $payload = [
                'name' => $row['name'],
                'type' => $row['type'],
                'description' => $row['description'] ?? null,
                'rarity' => $row['rarity'] ?? null,
                'price' => (int) ($row['price'] ?? 0),
                'sell_price' => (int) ($row['sell_price'] ?? 0),
                'hp_bonus' => (int) ($row['hp_bonus'] ?? 0),
                'mp_bonus' => (int) ($row['mp_bonus'] ?? 0),
                'str_bonus' => (int) ($row['str_bonus'] ?? 0),
                'def_bonus' => (int) ($row['def_bonus'] ?? 0),
                'agi_bonus' => (int) ($row['agi_bonus'] ?? 0),
                'mag_bonus' => (int) ($row['mag_bonus'] ?? 0),
                'spr_bonus' => (int) ($row['spr_bonus'] ?? 0),
                'luk_bonus' => (int) ($row['luk_bonus'] ?? 0),
                'required_level' => (int) ($row['required_level'] ?? 1),
                'is_shop_item' => (bool) ($row['is_shop_item'] ?? false),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'unlock_city_id' => $row['unlock_city_id'] ?? null,
                'sub_type' => $row['sub_type'] ?? null,
                'element' => $row['element'] ?? null,
                'updated_at' => $now,
            ];

            foreach ($this->optionalItemColumns($row) as $column => $value) {
                if (Schema::hasColumn('items', $column)) {
                    $payload[$column] = $value;
                }
            }

            $existing = DB::table('items')->where('external_item_id', $externalId)->first();
            if ($existing) {
                DB::table('items')->where('id', $existing->id)->update($payload);
            } else {
                $payload['external_item_id'] = $externalId;
                $payload['created_at'] = $now;
                DB::table('items')->insert($payload);
            }
        }
    }

    private function optionalItemColumns(array $row): array
    {
        $columns = [
            'weapon_category',
            'weapon_hand_type',
            'weapon_role',
            'weapon_family_id',
            'weapon_family_name',
            'weapon_rank',
            'weapon_rank_sort',
            'weapon_rank_multiplier',
            'evolution_stage',
            'next_item_external_id',
            'is_evolution_enabled',
            'is_drop_enabled',
            'is_supply_enabled',
            'max_enhance',
            'armor_category',
            'armor_weight',
            'armor_role',
            'armor_family_id',
            'armor_family_name',
            'armor_category_id',
            'armor_category_name',
            'armor_rank',
            'armor_rank_sort',
            'armor_rank_multiplier',
            'evolution_group_id',
            'next_armor_external_id',
            'accessory_family_id',
            'accessory_family_name',
            'accessory_category_id',
            'accessory_category_name',
            'accessory_rank',
            'accessory_rank_sort',
            'accessory_rank_multiplier',
            'next_accessory_external_id',
        ];

        $payload = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            if (in_array($column, ['next_item_external_id', 'next_armor_external_id', 'next_accessory_external_id'], true) && $value !== null) {
                $value = (string) $value;
            }

            if (in_array($column, ['is_evolution_enabled', 'is_drop_enabled', 'is_supply_enabled'], true)) {
                $value = (bool) $value;
            }

            if (in_array($column, ['weapon_rank_sort', 'armor_rank_sort', 'accessory_rank_sort', 'evolution_stage', 'max_enhance'], true)) {
                $value = (int) ($value ?? 0);
            }

            if (in_array($column, ['weapon_rank_multiplier', 'armor_rank_multiplier', 'accessory_rank_multiplier'], true)) {
                $value = (float) ($value ?? 1);
            }

            $payload[$column] = $value;
        }

        return $payload;
    }

    private function importRecipes(array $recipes, array $ingredients, $now): void
    {
        $recipeIdsByType = [];
        foreach ($recipes as $recipe) {
            $recipeIdsByType[$recipe['type']][] = $recipe['recipe_id'];
        }

        $this->clearBranchIngredients($recipeIdsByType);

        foreach ($recipes as $recipe) {
            match ($recipe['type']) {
                'weapon' => $this->upsertWeaponRecipe($recipe, $now),
                'armor' => $this->upsertArmorRecipe($recipe, $now),
                'accessory' => $this->upsertAccessoryRecipe($recipe, $now),
                default => null,
            };
        }

        foreach ($ingredients as $ingredient) {
            match ($ingredient['type']) {
                'weapon' => $this->insertWeaponIngredient($ingredient, $now),
                'armor' => $this->insertArmorIngredient($ingredient, $now),
                'accessory' => $this->insertAccessoryIngredient($ingredient, $now),
                default => null,
            };
        }
    }

    private function clearBranchIngredients(array $recipeIdsByType): void
    {
        if (!empty($recipeIdsByType['weapon']) && Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('recipe_id', $recipeIdsByType['weapon'])
                ->delete();
        }

        if (!empty($recipeIdsByType['armor']) && Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', $recipeIdsByType['armor'])
                ->delete();
        }

        if (!empty($recipeIdsByType['accessory']) && Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->whereIn('recipe_id', $recipeIdsByType['accessory'])
                ->delete();
        }
    }

    private function upsertWeaponRecipe(array $recipe, $now): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes')) {
            return;
        }

        DB::table('weapon_evolution_recipes')->updateOrInsert(
            ['recipe_id' => $recipe['recipe_id']],
            [
                'from_weapon_id' => (string) $recipe['from_external_item_id'],
                'from_weapon_name' => $recipe['from_name'],
                'to_weapon_id' => (string) $recipe['to_external_item_id'],
                'to_weapon_name' => $recipe['to_name'],
                'weapon_family_id' => null,
                'category_id' => null,
                'from_rank' => $recipe['from_rank'],
                'to_rank' => $recipe['to_rank'],
                'same_weapon_count' => 1,
                'unlock_condition' => $recipe['branch_name'] ?? null,
                'is_active' => true,
                'note' => $recipe['note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function upsertArmorRecipe(array $recipe, $now): void
    {
        if (!Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::table('armor_evolution_recipes')->updateOrInsert(
            ['evolution_recipe_id' => $recipe['recipe_id']],
            [
                'source_armor_id' => (string) $recipe['from_external_item_id'],
                'source_armor_name' => $recipe['from_name'],
                'target_armor_id' => (string) $recipe['to_external_item_id'],
                'target_armor_name' => $recipe['to_name'],
                'armor_family_id' => 'BR_' . strtoupper((string) $recipe['branch_key']) . '_ARMOR',
                'armor_family_name' => $recipe['branch_name'],
                'from_rank' => $recipe['from_rank'],
                'to_rank' => $recipe['to_rank'],
                'required_same_armor_count' => 1,
                'required_gold' => 0,
                'required_kiseki' => 0,
                'unlock_city_id' => null,
                'unlock_condition' => $recipe['branch_name'] ?? null,
                'success_rate' => 100,
                'is_active' => true,
                'implementation_note' => $recipe['note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function upsertAccessoryRecipe(array $recipe, $now): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        DB::table('accessory_evolution_recipes')->updateOrInsert(
            ['recipe_id' => $recipe['recipe_id']],
            [
                'from_accessory_id' => (string) $recipe['from_external_item_id'],
                'from_accessory_name' => $recipe['from_name'],
                'to_accessory_id' => (string) $recipe['to_external_item_id'],
                'to_accessory_name' => $recipe['to_name'],
                'from_rank' => $recipe['from_rank'],
                'to_rank' => $recipe['to_rank'],
                'required_same_accessory_count' => 1,
                'unlock_city_id' => null,
                'requires_city7_boss_cleared' => false,
                'requires_hidden_dungeon_unlocked' => false,
                'requires_hidden_boss_cleared' => false,
                'requires_demon_king_cleared' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function insertWeaponIngredient(array $ingredient, $now): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')->insert([
            'recipe_id' => $ingredient['recipe_id'],
            'ingredient_type' => 'material',
            'ingredient_id' => $ingredient['material_code'],
            'ingredient_name' => $ingredient['material_name'],
            'quantity' => (int) $ingredient['quantity'],
            'resolve_rule' => 'fixed',
            'is_consumed' => (bool) ($ingredient['is_consumed'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertArmorIngredient(array $ingredient, $now): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')->insert([
            'ingredient_id' => 'BR_' . $ingredient['recipe_id'] . '_' . $ingredient['material_code'],
            'evolution_recipe_id' => $ingredient['recipe_id'],
            'ingredient_type' => 'branch_material',
            'material_id' => $ingredient['material_code'],
            'material_name' => $ingredient['material_name'],
            'required_quantity' => (int) $ingredient['quantity'],
            'resolve_rule' => 'fixed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertAccessoryIngredient(array $ingredient, $now): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')->insert([
            'recipe_id' => $ingredient['recipe_id'],
            'ingredient_type' => 'material',
            'material_code' => $ingredient['material_code'],
            'material_name' => $ingredient['material_name'],
            'required_quantity' => (int) $ingredient['quantity'],
            'is_consumed' => (bool) ($ingredient['is_consumed'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
