<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FERDIA_AREA_ID_MIN = 1001;
    private const FERDIA_AREA_ID_MAX = 1013;
    private const MAX_NORMAL_MATERIAL_DROP_RATE = 33.0;
    // フェルディア通常戦の現行出現ウェイト込み期待値 12.455384...% を25%へ揃えた上で上限を適用する。
    private const NON_ANCIENT_DROP_RATE_MULTIPLIER = 8125 / 4048;
    private const CAPPED_DROP_RESTORE_RATES = [
        [1001, 'スライム', 'MAT_FERDIA_HEMOSTATIC_MOSS', 40.14],
        [1001, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 48.17],
        [1001, '水棲', 'MAT_FERDIA_BLUE_LIFE_LEAF', 40.14],
        [1002, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 40.14],
        [1003, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 48.17],
        [1004, 'スライム', 'MAT_FERDIA_CLEARSTREAM_DROP', 48.17],
        [1004, '精霊', 'MAT_FERDIA_CLEARSTREAM_DROP', 48.17],
        [1005, 'スライム', 'MAT_FERDIA_HEMOSTATIC_MOSS', 40.14],
        [1007, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 40.14],
        [1008, 'スライム', 'MAT_FERDIA_BLUE_LIFE_LEAF', 40.14],
        [1008, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 48.17],
        [1008, '昆虫', 'MAT_FERDIA_BLUE_LIFE_LEAF', 40.14],
        [1009, 'スライム', 'MAT_FERDIA_CLEARSTREAM_DROP', 40.14],
        [1009, '水棲', 'MAT_FERDIA_CLEARSTREAM_DROP', 40.14],
        [1010, 'スライム', 'MAT_FERDIA_GUARDTREE_RESIN', 40.14],
        [1010, '妖精', 'MAT_FERDIA_GUARDTREE_RESIN', 40.14],
        [1011, 'スライム', 'MAT_FERDIA_GUARDTREE_RESIN', 48.17],
        [1012, 'スライム', 'MAT_FERDIA_GUARDTREE_RESIN', 40.14],
    ];

    public function up(): void
    {
        $this->scaleNonAncientDropRates(self::NON_ANCIENT_DROP_RATE_MULTIPLIER);
    }

    public function down(): void
    {
        $this->restoreCappedDropRates();
        $this->scaleNonAncientDropRates(1 / self::NON_ANCIENT_DROP_RATE_MULTIPLIER);
    }

    private function scaleNonAncientDropRates(float $multiplier): void
    {
        $enemyIds = DB::table('enemies')
            ->whereBetween('area_id', [self::FERDIA_AREA_ID_MIN, self::FERDIA_AREA_ID_MAX])
            ->where('is_boss', false)
            ->pluck('id');

        if ($enemyIds->isEmpty()) {
            return;
        }

        $dropIds = DB::table('material_drops as material_drop')
            ->join('materials as material', 'material.id', '=', 'material_drop.material_id')
            ->whereIn('material_drop.enemy_id', $enemyIds)
            ->where('material_drop.is_active', true)
            ->where('material_drop.drop_first_clear_only', false)
            ->where('material.name', 'not like', '%古代片%')
            ->pluck('material_drop.id');

        DB::table('material_drops')
            ->whereIn('id', $dropIds)
            ->orderBy('id')
            ->each(function (object $drop) use ($multiplier): void {
                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update(['drop_rate' => min(self::MAX_NORMAL_MATERIAL_DROP_RATE, round((float) $drop->drop_rate * $multiplier, 2))]);
            });
    }

    private function restoreCappedDropRates(): void
    {
        foreach (self::CAPPED_DROP_RESTORE_RATES as [$areaId, $typeName, $materialCode, $dropRate]) {
            $dropIds = DB::table('material_drops as material_drop')
                ->join('enemies as enemy', 'enemy.id', '=', 'material_drop.enemy_id')
                ->join('materials as material', 'material.id', '=', 'material_drop.material_id')
                ->where('enemy.area_id', $areaId)
                ->where('enemy.is_boss', false)
                ->where('enemy.type_name', $typeName)
                ->where('material.material_code', $materialCode)
                ->where('material_drop.drop_rate', self::MAX_NORMAL_MATERIAL_DROP_RATE)
                ->pluck('material_drop.id');

            DB::table('material_drops')->whereIn('id', $dropIds)->update(['drop_rate' => $dropRate]);
        }
    }
};
