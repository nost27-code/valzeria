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

        $now = now();

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_HOLY_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で精霊王の絹糸、天界の羽根、天空の羽布、天空竜の織布、王都の守護布から錬成します。',
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_DARK_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で深魔の黒布、深淵の欠片、魔王城の黒布、魔王の黒装片、瘴気の革片から錬成します。',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_HOLY_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で精霊王の絹糸、天界の羽根、天空の羽布、天空竜の織布から錬成します。',
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_DARK_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で深魔の黒布、深淵の欠片、魔王城の黒布、魔王の黒装片から錬成します。',
                'updated_at' => $now,
            ]);
    }
};
