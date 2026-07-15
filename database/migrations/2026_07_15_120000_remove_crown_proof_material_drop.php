<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_CODE = 'MAT_FERDIA_CROWN_PROOF';

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $materialId = DB::table('materials')->where('material_code', self::MATERIAL_CODE)->value('id');
        if ($materialId) {
            DB::table('material_drops')->where('material_id', $materialId)->delete();
        }
    }

    public function down(): void
    {
        // 既存プレイヤーの誤付与済み素材は、この移行では変更しない。
    }
};
