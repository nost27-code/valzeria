<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();

        DB::table('materials')->updateOrInsert(
            ['material_code' => 'MAT_REFINING_CORE'],
            [
                'name' => '精錬核',
                'category' => '強化素材',
                'rarity' => 'SR',
                'element' => null,
                'main_use' => '鍛冶強化+5',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => 'enhance',
                'category_id' => null,
                'rank_tier' => 4,
                'is_consumable' => true,
                'obtain_method' => '素材交換所で高位共通素材と都市高位素材から錬成します。',
                'usage_tags' => json_encode(['鍛冶', '交換所'], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('materials')
            ->whereIn('material_code', ['MAT_ENHANCE_HIGH_STONE', '5009', 'ACC0009'])
            ->update([
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'obtain_method' => '素材交換所で強化石系素材から精製します。敵からは直接ドロップしません。',
                'usage_tags' => json_encode(['鍛冶', '交換所'], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->where('material_code', 'MAT_REFINING_CORE')
            ->delete();
    }
};
