<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_COMMON_FEATHER';

    private const DROPS = [
        ['area_id' => 15, 'enemy_name' => '若葉フェアリー', 'drop_rate' => 12],
        ['area_id' => 16, 'enemy_name' => '森フェアリー', 'drop_rate' => 15],
        ['area_id' => 18, 'enemy_name' => '中層風妖精', 'drop_rate' => 18],
        ['area_id' => 18, 'enemy_name' => '中層ハーピー', 'drop_rate' => 28],
        ['area_id' => 19, 'enemy_name' => '上層風妖精', 'drop_rate' => 22],
        ['area_id' => 19, 'enemy_name' => '上層ハーピー', 'drop_rate' => 35],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        DB::transaction(function (): void {
            $materialId = $this->ensureMaterial();

            foreach (self::DROPS as $drop) {
                $enemy = DB::table('enemies')
                    ->where('area_id', $drop['area_id'])
                    ->where('name', $drop['enemy_name'])
                    ->where('is_boss', false)
                    ->first();

                if (!$enemy) {
                    continue;
                }

                $existing = DB::table('material_drops')
                    ->where('enemy_id', $enemy->id)
                    ->where('material_id', $materialId)
                    ->first();

                DB::table('material_drops')->updateOrInsert(
                    ['enemy_id' => $enemy->id, 'material_id' => $materialId],
                    [
                        'drop_rate' => $existing ? max((float) $existing->drop_rate, (float) $drop['drop_rate']) : (float) $drop['drop_rate'],
                        'drop_first_clear_only' => false,
                        'drop_timing' => null,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => $existing->created_at ?? now(),
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        $materialId = DB::table('materials')
            ->where('material_code', self::MATERIAL_CODE)
            ->value('id');

        if (!$materialId) {
            return;
        }

        foreach (self::DROPS as $drop) {
            $enemyId = DB::table('enemies')
                ->where('area_id', $drop['area_id'])
                ->where('name', $drop['enemy_name'])
                ->value('id');

            if (!$enemyId) {
                continue;
            }

            DB::table('material_drops')
                ->where('enemy_id', $enemyId)
                ->where('material_id', $materialId)
                ->delete();
        }
    }

    private function ensureMaterial(): int
    {
        DB::table('materials')->updateOrInsert(
            ['material_code' => self::MATERIAL_CODE],
            array_merge(NormalDropMaterialConsolidator::payload(self::MATERIAL_CODE), [
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ])
        );

        return (int) DB::table('materials')
            ->where('material_code', self::MATERIAL_CODE)
            ->value('id');
    }
};
