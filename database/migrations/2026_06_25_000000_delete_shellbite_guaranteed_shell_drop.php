<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $enemyId = DB::table('enemies')->where('name', '海蝕甲殻獣シェルバイト')->value('id');
        $materialId = DB::table('materials')->where('material_code', 'MAT_COMMON_MONSTER_SHELL')->value('id');

        if ($enemyId && $materialId) {
            DB::table('material_drops')
                ->where('enemy_id', $enemyId)
                ->where('material_id', $materialId)
                ->where('drop_rate', '>=', 100)
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way migration
    }
};
