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
        $payload = [
            'name' => '素材引換券',
            'category' => '特殊素材',
            'rarity' => 'SR',
            'element' => null,
            'main_use' => '素材交換所で任意の通常素材と交換',
            'npc_sale_price' => 0,
            'is_tradable' => false,
            'city_id' => null,
            'dungeon_id' => null,
            'source_enemy_id' => null,
            'updated_at' => $now,
        ];

        foreach ([
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
            'material_type' => 'exchange_ticket',
            'category_id' => 'special',
            'rank_tier' => 3,
            'is_consumable' => true,
            'obtain_method' => '黄金ゴブリン報酬',
        ] as $column => $value) {
            if (Schema::hasColumn('materials', $column)) {
                $payload[$column] = $value;
            }
        }

        if (!DB::table('materials')->where('material_code', 'MAT_EXCHANGE_TICKET')->exists()) {
            $payload['created_at'] = $now;
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => 'MAT_EXCHANGE_TICKET'],
            $payload
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->where('material_code', 'MAT_EXCHANGE_TICKET')
            ->delete();
    }
};
