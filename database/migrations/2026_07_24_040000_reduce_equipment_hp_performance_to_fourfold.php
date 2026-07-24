<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FULL_SCALE_FACTOR = 8;
    private const HP_SCALE_FACTOR = 4;

    public function up(): void
    {
        DB::transaction(function (): void {
            $hpScaleExpression = fn (string $column): string => sprintf(
                'ROUND(COALESCE(%s, 0) * %d * 1.0 / %d + 0.25, 0)',
                $column,
                self::HP_SCALE_FACTOR,
                self::FULL_SCALE_FACTOR,
            );

            DB::table('items')
                ->whereIn('type', ['weapon', 'armor', 'accessory'])
                ->update([
                    'hp_bonus' => DB::raw($hpScaleExpression('hp_bonus')),
                ]);

            DB::table('character_items')
                ->whereIn('item_id', DB::table('items')
                    ->whereIn('type', ['weapon', 'armor', 'accessory'])
                    ->select('id'))
                ->update([
                    // 動的な銘は実行時に再計算される。ここでは旧方式で保存されたHP銘だけを補正する。
                    'affix_hp_bonus' => DB::raw($hpScaleExpression('affix_hp_bonus')),
                ]);
        });
    }

    public function down(): void
    {
        // 4倍化後に個別のHP補正が変わり得るため、機械的な2倍戻しは行わない。
        throw new RuntimeException('装備HP性能の4倍化は不可逆です。リリース前バックアップから復旧してください。');
    }
};
