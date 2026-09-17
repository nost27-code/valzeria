<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FRAGMENT_CODE = 'ACC0004';

    public function up(): void
    {
        foreach (['accessory_evolution_recipes', 'accessory_evolution_recipe_ingredients', 'items', 'materials', 'enemies', 'material_drops'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required table [{$table}] is missing.");
            }
        }

        DB::transaction(function (): void {
            $recipes = DB::table('accessory_evolution_recipes')
                ->where('is_active', true)
                ->where('recipe_id', 'like', 'ACC_EVO_%')
                ->where('from_rank', 'S')
                ->where('to_rank', 'SS')
                ->lockForUpdate()
                ->get();

            if ($recipes->count() !== 10) {
                throw new RuntimeException('Expected ten active standard accessory S-to-SS recipes. No rows were changed.');
            }

            $fragment = DB::table('materials')
                ->where('material_code', self::FRAGMENT_CODE)
                ->lockForUpdate()
                ->first();
            if (! $fragment || $fragment->name !== '古代装飾片') {
                throw new RuntimeException('Ancient accessory fragment master is missing or unexpected. No rows were changed.');
            }

            foreach ($recipes as $recipe) {
                $ingredients = DB::table('accessory_evolution_recipe_ingredients')
                    ->where('recipe_id', $recipe->recipe_id)
                    ->get();
                $fromItem = DB::table('items')->where('external_item_id', $recipe->from_accessory_id)->first();
                $toItem = DB::table('items')->where('external_item_id', $recipe->to_accessory_id)->first();

                if ($recipe->unlock_city_id !== null
                    || (bool) $recipe->requires_city7_boss_cleared
                    || (bool) $recipe->requires_hidden_boss_cleared
                    || (bool) $recipe->requires_demon_king_cleared
                    || (int) $recipe->required_same_accessory_count !== 1
                    || ! $fromItem || $fromItem->type !== 'accessory' || $fromItem->accessory_rank !== 'S' || ! (bool) $fromItem->is_active
                    || ! $toItem || $toItem->type !== 'accessory' || $toItem->accessory_rank !== 'SS' || ! (bool) $toItem->is_active
                    || $fromItem->next_accessory_external_id !== $recipe->to_accessory_id
                    || $ingredients->count() !== 1
                    || $ingredients[0]->material_code !== self::FRAGMENT_CODE
                    || (int) $ingredients[0]->required_quantity !== 3
                    || ! (bool) $ingredients[0]->is_consumed) {
                    throw new RuntimeException("Unexpected accessory S-to-SS recipe [{$recipe->recipe_id}]. No rows were changed.");
                }
            }

            $enemies = DB::table('enemies')
                ->whereBetween('area_id', [1001, 1013])
                ->where('is_boss', false)
                ->whereIn('type_name', ['人型', '巨人'])
                ->get(['id']);
            if ($enemies->count() !== 26) {
                throw new RuntimeException('Expected 26 Ferdia fragment enemies. No rows were changed.');
            }

            $dropRates = [];
            foreach ($enemies as $enemy) {
                $sourceDrops = DB::table('material_drops as drop')
                    ->join('materials as material', 'material.id', '=', 'drop.material_id')
                    ->where('drop.enemy_id', $enemy->id)
                    ->where('drop.is_active', true)
                    ->where('material.material_type', 'branch_evolution')
                    ->where('material.material_code', 'like', '%_ANCIENT')
                    ->get(['drop.drop_rate']);

                if ($sourceDrops->count() !== 1 || (float) $sourceDrops[0]->drop_rate <= 0) {
                    throw new RuntimeException("Unexpected ancient fragment drop for enemy [{$enemy->id}]. No rows were changed.");
                }
                $dropRates[(int) $enemy->id] = $sourceDrops[0]->drop_rate;
            }

            $now = now();
            DB::table('accessory_evolution_recipes')
                ->whereIn('id', $recipes->pluck('id'))
                ->update([
                    'requires_hidden_dungeon_unlocked' => false,
                    'updated_at' => $now,
                ]);

            $guide = [
                'main_use' => '装飾品S→SSの進化に利用します。',
                'obtain_method' => '探索の地図やフェルディア地方で入手できます。',
                'usage_summary' => '装飾品S→SSの進化に利用します。',
                'acquisition_summary' => '探索の地図やフェルディア地方の敵・輝く宝箱から入手できます。',
                'updated_at' => $now,
            ];
            foreach (array_keys($guide) as $column) {
                if (! Schema::hasColumn('materials', $column)) {
                    unset($guide[$column]);
                }
            }
            DB::table('materials')->where('id', $fragment->id)->update($guide);

            foreach ($dropRates as $enemyId => $rate) {
                $existing = DB::table('material_drops')
                    ->where('enemy_id', $enemyId)
                    ->where('material_id', $fragment->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((float) $existing->drop_rate <= 0 || ! (bool) $existing->is_active) {
                        throw new RuntimeException("Existing accessory fragment drop for enemy [{$enemyId}] differs. No rows were changed.");
                    }

                    continue;
                }

                DB::table('material_drops')->insert([
                    'enemy_id' => $enemyId,
                    'material_id' => $fragment->id,
                    'drop_rate' => $rate,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // 公開後に進化・素材入手したプレイヤーデータを守るため、巻き戻しは別のforward migrationで裁定する。
    }
};
