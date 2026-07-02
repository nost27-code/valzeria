<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'WEV0029';

    public function up(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        $materialId = DB::table('materials')
            ->where('material_code', self::MATERIAL_CODE)
            ->value('id');

        if (! $materialId) {
            return;
        }

        foreach ([
            'material_drops',
            'character_materials',
            'valmon_material_find_logs',
            'npc_material_stocks',
            'npc_procurement_request_materials',
            'npc_procurement_deliveries',
            'npc_procurement_request_template_materials',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('material_id', $materialId)->delete();
            }
        }

        if (Schema::hasTable('market_listings')) {
            DB::table('market_listings')->where('material_id', $materialId)->delete();
        }

        if (Schema::hasTable('market_transactions')) {
            DB::table('market_transactions')->where('material_id', $materialId)->update(['material_id' => null]);
        }

        if (Schema::hasTable('exploration_loot_logs')) {
            DB::table('exploration_loot_logs')->where('material_id', $materialId)->update(['material_id' => null]);
        }

        if (Schema::hasTable('champ_battle_logs')) {
            DB::table('champ_battle_logs')->where('material_id', $materialId)->update(['material_id' => null]);
        }

        DB::table('materials')->where('id', $materialId)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => self::MATERIAL_CODE],
            [
                'name' => 'ルミナス魔導晶',
                'category' => '武器合成素材',
                'rarity' => 'R',
                'element' => null,
                'main_use' => '武器進化・合成',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => 7,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => 'weapon_city',
                'category_id' => null,
                'rank_tier' => 2,
                'is_consumable' => true,
                'obtain_method' => 'C→B、B→A帯で使う魔導学院ルミナスの都市素材。',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
};
