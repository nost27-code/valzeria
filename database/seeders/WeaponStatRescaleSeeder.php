<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 武器のSTR/MAG主能力を config/equipment_scaling.php の「固定値倍率」で再計算する。
 *
 * items.str_bonus_base / mag_bonus_base に「倍率適用前の基準値」を一度だけ確定し、
 * 以後は常にその基準値 × 倍率 で str_bonus/mag_bonus を上書きするため、
 * 本Seederは何度実行しても数値が重複して増えない（冪等）。
 *
 * 固定値の変更を切り戻す場合は config/equipment_scaling.php の
 * fixed_multiplier をすべて 1.0 に戻し、本Seederを再実行すればよい。
 */
class WeaponStatRescaleSeeder extends Seeder
{
    public function run(): void
    {
        // 1. 基準値の確定（未確定の行のみ。既に確定済みなら何もしない）
        DB::table('items')
            ->where('type', 'weapon')
            ->whereNull('str_bonus_base')
            ->update(['str_bonus_base' => DB::raw('str_bonus')]);

        DB::table('items')
            ->where('type', 'weapon')
            ->whereNull('mag_bonus_base')
            ->update(['mag_bonus_base' => DB::raw('mag_bonus')]);

        // 2. 基準値 × ランク別固定値倍率 で常に再計算する（冪等）
        $multipliers = (array) config('equipment_scaling.weapon.fixed_multiplier', []);
        $updated = 0;

        Item::query()
            ->where('type', 'weapon')
            ->select(['id', 'weapon_rank', 'str_bonus', 'mag_bonus', 'str_bonus_base', 'mag_bonus_base'])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($multipliers, &$updated) {
                foreach ($items as $item) {
                    $newStr = self::scaledValue((int) $item->str_bonus_base, (string) $item->weapon_rank, $multipliers);
                    $newMag = self::scaledValue((int) $item->mag_bonus_base, (string) $item->weapon_rank, $multipliers);

                    if ($newStr === (int) $item->str_bonus && $newMag === (int) $item->mag_bonus) {
                        continue;
                    }

                    Item::query()->whereKey($item->id)->update([
                        'str_bonus' => $newStr,
                        'mag_bonus' => $newMag,
                    ]);
                    $updated++;
                }
            });

        $this->command?->info("武器STR/MAGを基準値から再スケールしました（更新: {$updated}件）。");
    }

    /**
     * 基準値にランク別倍率を掛けて四捨五入した値を返す。DBを使わない純粋関数。
     *
     * @param  array<string, float>  $multipliers
     */
    public static function scaledValue(int $base, string $rank, array $multipliers): int
    {
        if ($base === 0) {
            return 0;
        }

        $multiplier = (float) ($multipliers[strtoupper($rank)] ?? 1.0);

        return (int) round($base * $multiplier);
    }
}
