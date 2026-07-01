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
        $materials = [
            'MAT_REFINING_CORE_PART_A' => [
                'name' => '覇王黒晶',
                'obtain_method' => '素材交換所で王紋鋼、砂王金晶、ヴァルゼリア黒核から錬成します。',
            ],
            'MAT_REFINING_CORE_PART_B' => [
                'name' => '蒼炉魔晶',
                'obtain_method' => '素材交換所で海鳴りの蒼鉱、炉心鋼、ルミナス魔晶、深魔骨核から錬成します。',
            ],
            'MAT_REFINING_CORE_PART_C' => [
                'name' => '星樹氷晶',
                'obtain_method' => '素材交換所で精霊樹の琥珀、氷帝晶、セレスティア星晶から錬成します。',
            ],
        ];

        foreach ($materials as $code => $payload) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                [
                    'name' => $payload['name'],
                    'category' => '強化素材',
                    'rarity' => 'SR',
                    'element' => null,
                    'main_use' => '精錬核錬成',
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
                    'obtain_method' => $payload['obtain_method'],
                    'usage_tags' => json_encode(['鍛冶', '交換所'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        DB::table('materials')
            ->where('material_code', 'MAT_REFINING_CORE')
            ->update([
                'obtain_method' => '素材交換所で魔物の魔核、覇王黒晶、蒼炉魔晶、星樹氷晶から錬成します。',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->whereIn('material_code', [
                'MAT_REFINING_CORE_PART_A',
                'MAT_REFINING_CORE_PART_B',
                'MAT_REFINING_CORE_PART_C',
            ])
            ->delete();

        DB::table('materials')
            ->where('material_code', 'MAT_REFINING_CORE')
            ->update([
                'obtain_method' => '素材交換所で高位共通素材と都市高位素材から錬成します。',
                'updated_at' => now(),
            ]);
    }
};
