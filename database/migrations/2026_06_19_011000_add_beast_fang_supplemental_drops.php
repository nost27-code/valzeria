<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_COMMON_BEAST_FANG';

    private const DROPS = [
        ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 25],
        ['area_id' => 1, 'enemy_name' => '草むらウルフ', 'drop_rate' => 45],
        ['area_id' => 4, 'enemy_name' => '丘ウルフ', 'drop_rate' => 45],
        ['area_id' => 4, 'enemy_name' => '銀毛ウルフ', 'drop_rate' => 45],
        ['area_id' => 4, 'enemy_name' => '群れの長', 'drop_rate' => 35],
        ['area_id' => 15, 'enemy_name' => '若葉ウルフ', 'drop_rate' => 35],
        ['area_id' => 16, 'enemy_name' => '妖精森ウルフ', 'drop_rate' => 35],
        ['area_id' => 29, 'enemy_name' => '白狼', 'drop_rate' => 35],
        ['area_id' => 30, 'enemy_name' => '氷牙ウルフ', 'drop_rate' => 35],
        ['area_id' => 32, 'enemy_name' => '白銀ウルフ', 'drop_rate' => 35],
        ['area_id' => 34, 'enemy_name' => '竜牙兵', 'drop_rate' => 35],
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

                $this->upsertDrop((int) $enemy->id, $materialId, (float) $drop['drop_rate']);
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

        $enemyIds = [];
        foreach (self::DROPS as $drop) {
            $enemyId = DB::table('enemies')
                ->where('area_id', $drop['area_id'])
                ->where('name', $drop['enemy_name'])
                ->value('id');

            if ($enemyId) {
                $enemyIds[] = (int) $enemyId;
            }
        }

        DB::table('material_drops')
            ->where('material_id', $materialId)
            ->whereIn('enemy_id', $enemyIds)
            ->delete();
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

    private function upsertDrop(int $enemyId, int $materialId, float $dropRate): void
    {
        $existing = DB::table('material_drops')
            ->where('enemy_id', $enemyId)
            ->where('material_id', $materialId)
            ->first();

        DB::table('material_drops')->updateOrInsert(
            ['enemy_id' => $enemyId, 'material_id' => $materialId],
            [
                'drop_rate' => $existing ? max((float) $existing->drop_rate, $dropRate) : $dropRate,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );
    }
};
