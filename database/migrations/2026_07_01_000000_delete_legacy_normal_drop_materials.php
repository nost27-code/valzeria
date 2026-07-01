<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * legacy_normal_drop は 2026_06_18_181000_consolidate_normal_drop_materials.php で
     * 共通素材へ統合済みの旧素材。所持数・ドロップは統合済みのため0件のはずだが、
     * 安全のため所持者がいる/現役ドロップが残っている行は削除対象から除外する。
     */
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $legacyIds = DB::table('materials')
            ->where('material_type', 'legacy_normal_drop')
            ->pluck('id');

        if ($legacyIds->isEmpty()) {
            return;
        }

        $ownedIds = Schema::hasTable('character_materials')
            ? DB::table('character_materials')
                ->whereIn('material_id', $legacyIds)
                ->where('quantity', '>', 0)
                ->pluck('material_id')
                ->all()
            : [];

        $droppedIds = Schema::hasTable('material_drops')
            ? DB::table('material_drops')
                ->whereIn('material_id', $legacyIds)
                ->where('is_active', true)
                ->where('drop_rate', '>', 0)
                ->pluck('material_id')
                ->all()
            : [];

        $deletableIds = $legacyIds->diff($ownedIds)->diff($droppedIds);

        if ($deletableIds->isEmpty()) {
            return;
        }

        DB::table('materials')->whereIn('id', $deletableIds)->delete();
    }

    public function down(): void
    {
        // 旧素材データは復元不要のため何もしない。
    }
};
