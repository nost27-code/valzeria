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
        $stones = [
            [
                'material_code' => 'MAT_WEAPON_EVOLUTION_STONE',
                'name' => '武器進化石',
                'category' => '武器進化素材',
                'main_use' => '武器進化の同名装備代替',
                'obtain_method' => '素材交換所で武器の欠片10個と交換',
            ],
            [
                'material_code' => 'MAT_ARMOR_EVOLUTION_STONE',
                'name' => '防具進化石',
                'category' => '防具進化素材',
                'main_use' => '防具進化の同名装備代替',
                'obtain_method' => '素材交換所で防具の欠片10個と交換',
            ],
            [
                'material_code' => 'MAT_ACCESSORY_EVOLUTION_STONE',
                'name' => '装飾進化石',
                'category' => '装飾進化素材',
                'main_use' => '装飾品進化の同名装備代替',
                'obtain_method' => '素材交換所で装飾の欠片10個と交換',
            ],
        ];

        foreach ($stones as $stone) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $stone['material_code']],
                [
                    'name' => $stone['name'],
                    'category' => $stone['category'],
                    'rarity' => 'R',
                    'element' => null,
                    'main_use' => $stone['main_use'],
                    'npc_sale_price' => 0,
                    'is_tradable' => false,
                    'city_id' => null,
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'material_type' => 'evolution_stone',
                    'category_id' => 'equipment_evolution',
                    'rank_tier' => 2,
                    'is_consumable' => true,
                    'obtain_method' => $stone['obtain_method'],
                    'created_at' => $now,
                    'updated_at' => $now,
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
                'MAT_WEAPON_EVOLUTION_STONE',
                'MAT_ARMOR_EVOLUTION_STONE',
                'MAT_ACCESSORY_EVOLUTION_STONE',
            ])
            ->delete();
    }
};
