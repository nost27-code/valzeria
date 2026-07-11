<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_BREW_HERB';

    private const DROP_TARGETS = [
        ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 15],
        ['area_id' => 1, 'enemy_name' => '草むらウルフ', 'drop_rate' => 15],
        ['area_id' => 1, 'enemy_name' => '草原コウモリ', 'drop_rate' => 15],
    ];

    public function up(): void
    {
        $now = now();
        $materialId = DB::table('materials')->where('material_code', self::MATERIAL_CODE)->value('id');

        if (!$materialId) {
            return;
        }

        DB::table('materials')->where('id', $materialId)->update([
            'name' => '薬草の若葉',
            'main_use' => '薬草の調合',
            'obtain_method' => 'はじまりの草原の自然系の敵から入手。',
            'updated_at' => $now,
        ]);

        foreach (self::DROP_TARGETS as $target) {
            $enemyId = DB::table('enemies')
                ->where('area_id', $target['area_id'])
                ->where('name', $target['enemy_name'])
                ->where('is_boss', false)
                ->value('id');

            if (!$enemyId) {
                continue;
            }

            DB::table('material_drops')->updateOrInsert(
                ['enemy_id' => $enemyId, 'material_id' => $materialId],
                [
                    'drop_rate' => $target['drop_rate'],
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $materialId = DB::table('materials')->where('material_code', self::MATERIAL_CODE)->value('id');

        if (!$materialId) {
            return;
        }

        $enemyIds = DB::table('enemies')
            ->where('area_id', 1)
            ->whereIn('name', array_column(self::DROP_TARGETS, 'enemy_name'))
            ->pluck('id');

        DB::table('material_drops')
            ->where('material_id', $materialId)
            ->whereIn('enemy_id', $enemyIds)
            ->delete();

        // 既に入手済みのプレイヤー素材は削除しない。
        DB::table('materials')->where('id', $materialId)->update([
            'name' => '草素材',
            'main_use' => '回復アイテム調合',
            'obtain_method' => '素材交換所で敵が落とした部位素材を渡して入手。',
            'updated_at' => now(),
        ]);
    }
};
