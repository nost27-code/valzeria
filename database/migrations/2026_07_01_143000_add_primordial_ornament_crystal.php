<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_BR_ACC_PRIMORDIAL_ORNAMENT_CRYSTAL';
    private const MATERIAL_NAME = '原初装飾晶';

    public function up(): void
    {
        $this->upsertMaterial();
        $this->replaceAccessorySssIngredient(self::MATERIAL_CODE, self::MATERIAL_NAME, 1, false);
    }

    public function down(): void
    {
        $this->replaceAccessorySssIngredient('ACC0006', '秘境素材の欠片', 3, true);

        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_code', self::MATERIAL_CODE)
                ->update([
                    'main_use' => '廃止済み',
                    'updated_at' => now(),
                ]);
        }
    }

    private function upsertMaterial(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => self::MATERIAL_CODE],
            [
                'name' => self::MATERIAL_NAME,
                'category' => 'accessory_evolution',
                'rarity' => 'SSSR',
                'element' => null,
                'main_use' => '装飾品SS→SSS進化',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => 'accessory_evolution',
                'category_id' => 'accessory_primordial',
                'rank_tier' => 5,
                'is_consumable' => false,
                'obtain_method' => '素材交換所で聖剣・魔剣・迅刃・重装・魔装・軽装・旅装の秘境晶を各2個ずつ使って錬成します。',
                'market_category' => 'evolution',
                'trade_policy' => 'not_tradable',
                'npc_sell_price' => 0,
                'market_min_price' => null,
                'market_max_price' => null,
                'source_area_id' => null,
                'is_key_item' => false,
                'is_cash_item' => false,
                'usage_summary' => '装飾品SS→SSSの進化に利用します。',
                'acquisition_summary' => '7種類の秘境晶を各2個ずつ素材交換所で錬成します。',
                'usage_tags' => json_encode(['accessory_evolution', 'sss']),
                'acquisition_tags' => json_encode(['material_exchange', 'secret_crystal']),
                'market_hint' => null,
                'display_order' => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function replaceAccessorySssIngredient(string $materialCode, string $materialName, int $quantity, bool $requiresHiddenBoss): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('accessory_evolution_recipes')
            ->where('is_active', true)
            ->where('from_rank', 'SS')
            ->where('to_rank', 'SSS')
            ->pluck('recipe_id')
            ->all();

        if ($recipeIds === []) {
            return;
        }

        DB::table('accessory_evolution_recipes')
            ->whereIn('recipe_id', $recipeIds)
            ->update([
                'requires_hidden_boss_cleared' => $requiresHiddenBoss,
                'updated_at' => now(),
            ]);

        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->delete();

        foreach ($recipeIds as $recipeId) {
            DB::table('accessory_evolution_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'ingredient_type' => 'material',
                'material_code' => $materialCode,
                'material_name' => $materialName,
                'required_quantity' => $quantity,
                'is_consumed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
