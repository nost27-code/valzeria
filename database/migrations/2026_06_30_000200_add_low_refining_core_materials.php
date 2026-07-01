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
            'MAT_REFINING_CORE_LOW_A' => [
                'name' => '織成核殻',
                'obtain_method' => '素材交換所で王都の織布、潮風の布片、精霊樹の繊維、黒鉄の装甲片から錬成します。',
            ],
            'MAT_REFINING_CORE_LOW_B' => [
                'name' => '晶糸核芯',
                'obtain_method' => '素材交換所で氷晶の織糸、砂金繊維、魔導繊維から錬成します。',
            ],
            'MAT_REFINING_CORE_LOW' => [
                'name' => '粗精錬核',
                'obtain_method' => '素材交換所で織成核殻と晶糸核芯から錬成します。',
            ],
        ];

        foreach ($materials as $code => $payload) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                [
                    'name' => $payload['name'],
                    'category' => '強化素材',
                    'rarity' => 'R',
                    'element' => null,
                    'main_use' => '鍛冶強化+4',
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
                    'rank_tier' => 3,
                    'is_consumable' => true,
                    'obtain_method' => $payload['obtain_method'],
                    'usage_tags' => json_encode(['鍛冶', '交換所'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->whereIn('material_code', [
                'MAT_REFINING_CORE_LOW_A',
                'MAT_REFINING_CORE_LOW_B',
                'MAT_REFINING_CORE_LOW',
            ])
            ->delete();
    }
};
