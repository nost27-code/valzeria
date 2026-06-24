<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIALS = [
        'MAT_BREW_BEAST_FANG' => ['name' => '獣牙素材', 'rarity' => 'N', 'rank_tier' => 1, 'memo' => '牙・爪・毛皮などを調合用にまとめた素材。'],
        'MAT_BREW_TOXIN' => ['name' => '毒素材', 'rarity' => 'N', 'rank_tier' => 1, 'memo' => '毒や呪いを帯びた部位を調合用にまとめた素材。'],
        'MAT_BREW_HERB' => ['name' => '草素材', 'rarity' => 'N', 'rank_tier' => 1, 'memo' => '粘液・葉・花・樹液などを調合用にまとめた素材。'],
        'MAT_BREW_MAGIC_POWDER' => ['name' => '魔粉素材', 'rarity' => 'N+', 'rank_tier' => 1, 'memo' => '粉・結晶・魔核などを調合用にまとめた素材。'],
        'MAT_BREW_LOW_MONSTER' => ['name' => '低級魔物素材', 'rarity' => 'N', 'rank_tier' => 1, 'memo' => '分類しきれない低級魔物の部位を調合用にまとめた素材。'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        foreach (self::MATERIALS as $code => $material) {
            $payload = [
                'name' => $material['name'],
                'category' => '調合素材',
                'rarity' => $material['rarity'],
                'element' => null,
                'main_use' => '回復アイテム調合',
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
                'material_type' => 'brewing',
                'category_id' => 'brewing',
                'rank_tier' => $material['rank_tier'],
                'is_consumable' => true,
                'obtain_method' => '素材交換所で敵が落とした部位素材を渡して入手。',
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            if (Schema::hasColumn('materials', 'description')) {
                $payload['description'] = $material['memo'];
            }

            if (!DB::table('materials')->where('material_code', $code)->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(['material_code' => $code], $payload);
        }
    }

    public function down(): void
    {
        // Keep player-owned material rows intact on rollback.
    }
};
