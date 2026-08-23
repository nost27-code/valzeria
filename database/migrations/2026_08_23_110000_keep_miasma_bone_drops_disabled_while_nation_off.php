<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('materials') || ! Schema::hasTable('enemies') || ! Schema::hasTable('material_drops')) {
            return;
        }

        $materialId = DB::table('materials')->where('material_code', 'WEV0030')->value('id');
        if (! $materialId) {
            return;
        }

        $enemyIds = DB::table('enemies')
            ->whereIn('area_id', [50, 51, 52, 53, 54, 55, 56])
            ->whereIn('name', [
                '死霊兵', '黒骨犬', '呪い騎士', '吸血コウモリ', '冥界の番犬', '門番デーモン', '神殿兵',
                '封印の守護者', '魔王軍兵', '魔王軍弓兵', '瘴気スライム', '毒霧の悪魔', '奈落の影', '深淵騎士',
            ])
            ->where('is_boss', false)
            ->pluck('id');

        DB::table('material_drops')
            ->where('material_id', $materialId)
            ->whereIn('enemy_id', $enemyIds)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // OFF公開前の由来を安全に識別できないため、自動で再有効化しない。
    }
};
