<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FERDIA_AREA_ID_MIN = 1001;
    private const FERDIA_AREA_ID_MAX = 1013;

    public function up(): void
    {
        $this->scaleDropRates(2.0);
    }

    public function down(): void
    {
        $this->scaleDropRates(0.5);
    }

    private function scaleDropRates(float $multiplier): void
    {
        $enemyIds = DB::table('enemies')
            ->whereBetween('area_id', [self::FERDIA_AREA_ID_MIN, self::FERDIA_AREA_ID_MAX])
            ->pluck('id');

        if ($enemyIds->isEmpty()) {
            return;
        }

        DB::table('material_drops')
            ->whereIn('enemy_id', $enemyIds)
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->orderBy('id')
            ->each(function (object $drop) use ($multiplier): void {
                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update(['drop_rate' => min(100, (float) $drop->drop_rate * $multiplier)]);
            });
    }
};
