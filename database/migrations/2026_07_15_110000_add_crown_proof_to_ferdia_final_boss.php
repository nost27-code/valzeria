<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_FERDIA_CROWN_PROOF';
    private const MATERIAL_NAME = '冠位の証';
    private const FINAL_BOSS_AREA_ID = 1013;
    private const FINAL_BOSS_NAME = '霊峰の氷冠竜エルヴァン';

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('enemies') || !Schema::hasTable('material_drops')) {
            return;
        }

        $now = now();

        DB::table('materials')->updateOrInsert(
            ['material_code' => self::MATERIAL_CODE],
            [
                'name' => self::MATERIAL_NAME,
                'category' => 'フェルディア討伐証',
                'rarity' => 'KEY',
                'element' => '氷',
                'main_use' => '冠位職に至るための証',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => self::FINAL_BOSS_AREA_ID,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => 'key_item',
                'category_id' => 'ferdia_boss_proof',
                'rank_tier' => 5,
                'is_consumable' => false,
                'obtain_method' => '北境の霊峰エルヴァンの最終ボスを初めて倒す',
                'market_category' => 'key_item',
                'trade_policy' => 'not_tradable',
                'market_min_price' => null,
                'market_max_price' => null,
                'source_area_id' => self::FINAL_BOSS_AREA_ID,
                'is_key_item' => true,
                'is_cash_item' => false,
                'usage_summary' => '冠位職に至るための証です。',
                'acquisition_summary' => '北境の霊峰エルヴァンの最終ボスを初めて倒すと入手できます。',
                'usage_tags' => json_encode(['job_change', 'crown']),
                'acquisition_tags' => json_encode(['ferdia', 'boss', 'first_clear']),
                'market_hint' => '討伐で得た大切な証のため、売却・取引できません。',
                'display_order' => 0,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $bossId = DB::table('enemies')
            ->where('area_id', self::FINAL_BOSS_AREA_ID)
            ->where('name', self::FINAL_BOSS_NAME)
            ->where('is_boss', true)
            ->value('id');
        $materialId = DB::table('materials')
            ->where('material_code', self::MATERIAL_CODE)
            ->value('id');

        if (!$bossId || !$materialId) {
            return;
        }

        DB::table('materials')
            ->where('id', $materialId)
            ->update(['source_enemy_id' => $bossId, 'updated_at' => $now]);

        DB::table('material_drops')->updateOrInsert(
            ['enemy_id' => $bossId, 'material_id' => $materialId],
            [
                'drop_rate' => 100,
                'drop_first_clear_only' => true,
                'drop_timing' => 'boss_first_clear',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $materialId = DB::table('materials')
            ->where('material_code', self::MATERIAL_CODE)
            ->value('id');

        if (!$materialId) {
            return;
        }

        DB::table('material_drops')->where('material_id', $materialId)->delete();
        DB::table('materials')->where('id', $materialId)->update([
            'main_use' => '廃止済み',
            'updated_at' => now(),
        ]);
    }
};
