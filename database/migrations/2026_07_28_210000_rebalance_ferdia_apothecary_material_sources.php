<?php

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(FerdiaRegionSeeder::class)->seedMaterialDrops();
    }

    public function down(): void
    {
        $now = now();
        $materialIds = DB::table('materials')
            ->whereIn('material_code', [
                'MAT_FERDIA_BLUE_LIFE_LEAF',
                'MAT_FERDIA_GUARDTREE_RESIN',
                'MAT_FERDIA_DETOX_GALL',
            ])
            ->pluck('id', 'material_code');

        $area1005InsectIds = DB::table('enemies')
            ->where('area_id', 1005)
            ->where('is_boss', false)
            ->where('type_name', '昆虫')
            ->pluck('id');
        if ($materialIds->has('MAT_FERDIA_DETOX_GALL')) {
            DB::table('material_drops')
                ->whereIn('enemy_id', $area1005InsectIds)
                ->where('material_id', $materialIds['MAT_FERDIA_DETOX_GALL'])
                ->update(['is_active' => false, 'updated_at' => $now]);
        }

        $area1008InsectIds = DB::table('enemies')
            ->where('area_id', 1008)
            ->where('is_boss', false)
            ->where('type_name', '昆虫')
            ->pluck('id');
        if ($materialIds->has('MAT_FERDIA_GUARDTREE_RESIN')) {
            DB::table('material_drops')
                ->whereIn('enemy_id', $area1008InsectIds)
                ->where('material_id', $materialIds['MAT_FERDIA_GUARDTREE_RESIN'])
                ->update(['is_active' => false, 'updated_at' => $now]);
        }
        if ($materialIds->has('MAT_FERDIA_BLUE_LIFE_LEAF')) {
            foreach ($area1008InsectIds as $enemyId) {
                DB::table('material_drops')->updateOrInsert(
                    [
                        'enemy_id' => $enemyId,
                        'material_id' => $materialIds['MAT_FERDIA_BLUE_LIFE_LEAF'],
                    ],
                    [
                        'drop_rate' => 33.0,
                        'drop_first_clear_only' => false,
                        'drop_timing' => null,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
};
