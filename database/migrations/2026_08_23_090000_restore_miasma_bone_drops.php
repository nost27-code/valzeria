<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('materials') || ! Schema::hasTable('enemies') || ! Schema::hasTable('material_drops')) return;
        $materialId = DB::table('materials')->where('material_code', 'WEV0030')->value('id');
        if (! $materialId) return;
        $targets = [50=>['死霊兵','黒骨犬'],51=>['呪い騎士','吸血コウモリ'],52=>['冥界の番犬','門番デーモン'],53=>['神殿兵','封印の守護者'],54=>['魔王軍兵','魔王軍弓兵'],55=>['瘴気スライム','毒霧の悪魔'],56=>['奈落の影','深淵騎士']];
        foreach ($targets as $areaId => $names) {
            foreach (DB::table('enemies')->where('area_id', $areaId)->whereIn('name', $names)->where('is_boss', false)->pluck('id') as $enemyId) {
                DB::table('material_drops')->updateOrInsert(
                    ['enemy_id' => $enemyId, 'material_id' => $materialId],
                    ['drop_rate' => 18, 'drop_first_clear_only' => false, 'drop_timing' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        // 既存ドロップ由来かを識別する列がないため、安全のためデータは削除しない。
    }
};
