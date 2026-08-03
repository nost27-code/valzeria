<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DATA_PATH = 'database/data/katana_weapon_evolution_master.json';

    private const WEAPON_SCALE_VERSION = 2;

    private const STAT_COLUMNS = [
        'hp' => 'hp_bonus',
        'mp' => 'mp_bonus',
        'attack' => 'str_bonus',
        'defense' => 'def_bonus',
        'magic' => 'mag_bonus',
        'spirit' => 'spr_bonus',
        'speed' => 'agi_bonus',
        'luck' => 'luk_bonus',
    ];

    public function up(): void
    {
        foreach (['weapon_families', 'items', 'weapon_evolution_recipes', 'weapon_evolution_recipe_ingredients'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("刀武器の追加に必要な {$table} テーブルがありません。");
            }
        }

        $master = $this->loadMaster();

        DB::transaction(function () use ($master): void {
            $this->upsertFamily($master['family']);

            $itemNames = [];
            foreach ($master['items'] as $item) {
                $this->upsertItem($item);
                $itemNames[(string) $item['external_item_id']] = (string) $item['name'];
            }

            foreach ($master['recipes'] as $recipe) {
                $this->upsertRecipe($recipe, $itemNames);
            }
        });
    }

    public function down(): void
    {
        $master = $this->loadMaster();
        $externalItemIds = array_column($master['items'], 'external_item_id');
        $recipeIds = array_column($master['recipes'], 'recipe_id');

        if (
            Schema::hasTable('character_items')
            && DB::table('character_items')
                ->whereIn('item_id', DB::table('items')->whereIn('external_item_id', $externalItemIds)->select('id'))
                ->exists()
        ) {
            throw new RuntimeException('所持中の刀があるため、安全のため自動ロールバックできません。');
        }

        DB::transaction(function () use ($externalItemIds, $recipeIds): void {
            DB::table('weapon_evolution_recipe_ingredients')->whereIn('recipe_id', $recipeIds)->delete();
            DB::table('weapon_evolution_recipes')->whereIn('recipe_id', $recipeIds)->delete();
            DB::table('items')->whereIn('external_item_id', $externalItemIds)->delete();
            DB::table('weapon_families')->where('weapon_family_id', 'KATANA')->delete();
        });
    }

    private function loadMaster(): array
    {
        $path = base_path(self::DATA_PATH);
        if (!is_file($path)) {
            throw new RuntimeException(self::DATA_PATH . ' が見つかりません。');
        }

        $master = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (
            empty($master['family'])
            || count($master['items'] ?? []) !== 19
            || count($master['recipes'] ?? []) !== 18
        ) {
            throw new RuntimeException('刀武器進化マスタの件数または必須項目が不正です。');
        }

        return $master;
    }

    private function upsertFamily(array $family): void
    {
        $now = now();

        DB::table('weapon_families')->updateOrInsert(
            ['weapon_family_id' => (string) $family['weapon_family_id']],
            [
                'weapon_family_name' => (string) $family['weapon_family_name'],
                'category_id' => (string) $family['category_id'],
                'category_name' => (string) $family['category_name'],
                'base_hp' => (int) $family['base_hp'],
                'base_mp' => (int) $family['base_mp'],
                'base_atk' => (int) $family['base_atk'],
                'base_def' => (int) $family['base_def'],
                'base_mag' => (int) $family['base_mag'],
                'base_spr' => (int) $family['base_spr'],
                'base_spd' => (int) $family['base_spd'],
                'base_luk' => (int) $family['base_luk'],
                'trait' => (string) $family['trait'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function upsertItem(array $item): void
    {
        $externalItemId = (string) $item['external_item_id'];
        $name = (string) $item['name'];
        $template = DB::table('items')
            ->where('external_item_id', (string) $item['template_external_item_id'])
            ->first();

        if (!$template) {
            throw new RuntimeException("刀武器 {$externalItemId} の複製元アイテムがありません。");
        }

        $nameCollision = DB::table('items')
            ->where('name', $name)
            ->where('external_item_id', '!=', $externalItemId)
            ->exists();
        if ($nameCollision) {
            throw new RuntimeException("刀武器名「{$name}」が既存アイテムと重複しています。");
        }

        $payload = (array) $template;
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        $payload = array_merge($payload, [
            'external_item_id' => $externalItemId,
            'name' => $name,
            'type' => 'weapon',
            'description' => (string) $item['description'],
            'rarity' => (string) $item['rank'],
            'is_shop_item' => false,
            'is_active' => true,
            'sub_type' => '刀',
            'element' => $item['element'],
            'weapon_category' => 'katana',
            'weapon_family_id' => (string) $item['weapon_family_id'],
            'weapon_family_name' => (string) $item['weapon_family_name'],
            'weapon_rank' => (string) $item['rank'],
            'weapon_rank_sort' => (int) $item['rank_sort'],
            'weapon_rank_multiplier' => (float) $item['rank_multiplier'],
            'evolution_stage' => (int) $item['evolution_stage'],
            'next_item_external_id' => $item['next_item_external_id'],
            'is_evolution_enabled' => $item['next_item_external_id'] !== null || (string) $item['rank'] === 'A',
            'is_drop_enabled' => (bool) $item['is_drop_enabled'],
            'is_supply_enabled' => (bool) $item['is_supply_enabled'],
            'weapon_offense_scale_version' => self::WEAPON_SCALE_VERSION,
            'updated_at' => now(),
        ]);

        if (isset($item['sort_order'])) {
            $payload['sort_order'] = (int) $item['sort_order'];
        }
        if (Schema::hasColumn('items', 'is_evolvable')) {
            $payload['is_evolvable'] = $item['next_item_external_id'] !== null || (string) $item['rank'] === 'A';
        }

        foreach (self::STAT_COLUMNS as $masterKey => $column) {
            $factor = $masterKey === 'hp' ? 4 : 8;
            $payload[$column] = (int) ($item['stats'][$masterKey] ?? 0) * $factor;
        }
        if (Schema::hasColumn('items', 'str_bonus_base')) {
            $payload['str_bonus_base'] = $payload['str_bonus'];
        }
        if (Schema::hasColumn('items', 'mag_bonus_base')) {
            $payload['mag_bonus_base'] = $payload['mag_bonus'];
        }

        $existing = DB::table('items')->where('external_item_id', $externalItemId)->first();
        if ($existing) {
            DB::table('items')->where('id', $existing->id)->update($payload);
            return;
        }

        $payload['created_at'] = now();
        DB::table('items')->insert($payload);
    }

    private function upsertRecipe(array $recipe, array $itemNames): void
    {
        $recipeId = (string) $recipe['recipe_id'];
        $templateRecipeId = (string) $recipe['template_recipe_id'];
        $template = DB::table('weapon_evolution_recipes')
            ->where('recipe_id', $templateRecipeId)
            ->first();

        if (!$template) {
            throw new RuntimeException("刀武器進化 {$recipeId} の複製元レシピがありません。");
        }

        $fromExternalItemId = (string) $recipe['from_external_item_id'];
        $toExternalItemId = (string) $recipe['to_external_item_id'];
        $payload = (array) $template;
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        $payload = array_merge($payload, [
            'recipe_id' => $recipeId,
            'from_weapon_id' => $fromExternalItemId,
            'from_weapon_name' => $itemNames[$fromExternalItemId],
            'to_weapon_id' => $toExternalItemId,
            'to_weapon_name' => $itemNames[$toExternalItemId],
            'weapon_family_id' => $recipe['weapon_family_id'],
            'category_id' => $recipe['category_id'],
            'from_rank' => (string) $recipe['from_rank'],
            'to_rank' => (string) $recipe['to_rank'],
            'unlock_condition' => $recipe['branch_name'] ?? $template->unlock_condition,
            'is_active' => true,
            'note' => isset($recipe['branch_name'])
                ? "{$recipe['branch_name']}。既存武器と同じ分岐素材・解放条件を使う。"
                : '刀の基本進化。既存の斬撃武器と同じ素材・解放条件を使う。',
            'updated_at' => now(),
        ]);

        $existing = DB::table('weapon_evolution_recipes')->where('recipe_id', $recipeId)->first();
        if ($existing) {
            DB::table('weapon_evolution_recipes')->where('id', $existing->id)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('weapon_evolution_recipes')->insert($payload);
        }

        $templateIngredients = DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $templateRecipeId)
            ->orderBy('id')
            ->get();
        if ($templateIngredients->isEmpty()) {
            throw new RuntimeException("刀武器進化 {$recipeId} の複製元素材がありません。");
        }

        DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->delete();
        foreach ($templateIngredients as $ingredient) {
            $ingredientPayload = (array) $ingredient;
            unset($ingredientPayload['id'], $ingredientPayload['created_at'], $ingredientPayload['updated_at']);
            $ingredientPayload['recipe_id'] = $recipeId;
            if ((string) $ingredient->ingredient_type === 'same_weapon') {
                $ingredientPayload['ingredient_id'] = $fromExternalItemId;
                $ingredientPayload['ingredient_name'] = $itemNames[$fromExternalItemId];
            }
            $ingredientPayload['created_at'] = now();
            $ingredientPayload['updated_at'] = now();

            DB::table('weapon_evolution_recipe_ingredients')->insert($ingredientPayload);
        }
    }
};
