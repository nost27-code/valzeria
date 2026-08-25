<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = [
        50 => ['死霊兵', '黒骨犬'],
        51 => ['呪い騎士', '吸血コウモリ'],
        52 => ['冥界の番犬', '門番デーモン'],
        53 => ['魔神神殿兵', '魔神封印守'],
        54 => ['魔王軍兵', '魔王軍弓兵'],
        55 => ['瘴気スライム', '毒霧の悪魔'],
        56 => ['奈落の影', '深淵騎士'],
    ];

    public function up(): void
    {
        if (! $this->hasRequiredTables()) {
            return;
        }

        $materialId = DB::table('materials')->where('material_code', 'WEV0030')->value('id');
        if (! $materialId) {
            return;
        }

        foreach ($this->targetEnemyIds() as $enemyId) {
            $query = DB::table('material_drops')
                ->where('enemy_id', $enemyId)
                ->where('material_id', $materialId);

            $values = [
                'drop_rate' => 18,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'is_active' => true,
                'updated_at' => now(),
            ];

            if ($query->exists()) {
                $query->update($values);
            } else {
                DB::table('material_drops')->insert(array_merge($values, [
                    'enemy_id' => $enemyId,
                    'material_id' => $materialId,
                    'created_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        if (! $this->hasRequiredTables()) {
            return;
        }

        $materialId = DB::table('materials')->where('material_code', 'WEV0030')->value('id');
        if (! $materialId) {
            return;
        }

        DB::table('material_drops')
            ->where('material_id', $materialId)
            ->whereIn('enemy_id', $this->targetEnemyIds())
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /** @return list<int> */
    private function targetEnemyIds(): array
    {
        $ids = [];
        foreach (self::TARGETS as $areaId => $names) {
            foreach (DB::table('enemies')
                ->where('area_id', $areaId)
                ->whereIn('name', $names)
                ->where('is_boss', false)
                ->pluck('id') as $enemyId) {
                $ids[] = (int) $enemyId;
            }
        }

        return $ids;
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('materials')
            && Schema::hasTable('enemies')
            && Schema::hasTable('material_drops');
    }
};
