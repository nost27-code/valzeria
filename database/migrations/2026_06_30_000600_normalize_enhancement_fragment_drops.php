<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FRAGMENT_CODES = ['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'];
    private const DROP_RATE = 1.0;

    public function up(): void
    {
        if (!Schema::hasTable('areas') || !Schema::hasTable('enemies') || !Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $materials = DB::table('materials')
            ->whereIn('material_code', self::FRAGMENT_CODES)
            ->get(['id', 'material_code'])
            ->keyBy('material_code');

        if ($materials->count() === 0) {
            return;
        }

        $materialIds = $materials->pluck('id')->all();
        $now = now();

        DB::table('material_drops')
            ->whereIn('material_id', $materialIds)
            ->where('drop_first_clear_only', false)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);

        $enemiesByArea = DB::table('enemies')
            ->join('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('enemies.is_boss', false)
            ->where('areas.is_route_area', false)
            ->whereNotNull('areas.city_id')
            ->orderBy('enemies.area_id')
            ->orderBy('enemies.id')
            ->get(['enemies.id', 'enemies.area_id'])
            ->groupBy('area_id');

        foreach ($enemiesByArea as $areaEnemies) {
            $areaEnemies = $areaEnemies->values();
            foreach ($areaEnemies as $index => $enemy) {
                if ($index % 2 !== 0) {
                    continue;
                }

                foreach (self::FRAGMENT_CODES as $code) {
                    $material = $materials->get($code);
                    if (!$material) {
                        continue;
                    }

                    DB::table('material_drops')->updateOrInsert(
                        [
                            'enemy_id' => $enemy->id,
                            'material_id' => $material->id,
                        ],
                        [
                            'drop_rate' => self::DROP_RATE,
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
    }

    public function down(): void
    {
        // Data-only balance migration: do not restore previous fragment drop rates.
    }
};
