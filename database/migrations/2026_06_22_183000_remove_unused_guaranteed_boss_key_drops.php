<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::transaction(function (): void {
            $materialIds = $this->targetMaterialIds();
            if ($materialIds === []) {
                return;
            }

            if (Schema::hasTable('material_drops')) {
                DB::table('material_drops')
                    ->whereIn('material_id', $materialIds)
                    ->delete();
            }

            if (Schema::hasTable('character_materials')) {
                DB::table('character_materials')
                    ->whereIn('material_id', $materialIds)
                    ->delete();
            }

            DB::table('materials')
                ->whereIn('id', $materialIds)
                ->update([
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'source_enemy_id' => null,
                    'main_use' => '廃止',
                    'obtain_method' => '未使用のボス初回確定ドロップだったため廃止しました。',
                    'usage_summary' => '廃止',
                    'acquisition_summary' => '現在は入手できません。',
                    'is_tradable' => false,
                    'is_key_item' => false,
                    'trade_policy' => 'untradable',
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        // 廃止対象の所持品・初回確定ドロップは復元しない。
    }

    /**
     * @return array<int>
     */
    private function targetMaterialIds(): array
    {
        $query = DB::table('materials')
            ->where(function ($query): void {
                $query->whereIn('material_type', ['boss_unique', 'weapon_unlock_key'])
                    ->orWhere('category_id', 'boss_unique')
                    ->orWhere('name', 'like', '%進化証%');
            });

        if (!Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $query
            ->whereExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('material_drops')
                    ->join('enemies', 'enemies.id', '=', 'material_drops.enemy_id')
                    ->whereColumn('material_drops.material_id', 'materials.id')
                    ->where('enemies.is_boss', true)
                    ->where('material_drops.drop_rate', '>=', 100)
                    ->where('material_drops.drop_first_clear_only', true);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
