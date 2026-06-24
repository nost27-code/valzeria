<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        DB::transaction(function (): void {
            $query = DB::table('material_drops as drop')
                ->join('materials as material', 'material.id', '=', 'drop.material_id')
                ->join('enemies as enemy', 'enemy.id', '=', 'drop.enemy_id')
                ->where('material.material_type', 'branch_evolution')
                ->where('material.material_code', 'like', '%_PATH');

            if (Schema::hasTable('areas')) {
                $drops = $query
                    ->leftJoin('areas as area', 'area.id', '=', 'enemy.area_id')
                    ->select('drop.id', 'area.city_id')
                    ->get();
            } else {
                $drops = $query
                    ->select('drop.id', 'enemy.area_id')
                    ->get()
                    ->map(function ($drop) {
                        $drop->city_id = $this->cityIdFromAreaId((int) ($drop->area_id ?? 0));
                        return $drop;
                    });
            }

            foreach ($drops as $drop) {
                $cityId = (int) ($drop->city_id ?? 0);

                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update([
                        'drop_rate' => $cityId >= 4 ? 1 : 0,
                        'is_active' => $cityId >= 4,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Previous path-stone rates are not restored.
    }

    private function cityIdFromAreaId(int $areaId): int
    {
        if ($areaId <= 0) {
            return 0;
        }

        return max(1, (int) ceil($areaId / 7));
    }
};
