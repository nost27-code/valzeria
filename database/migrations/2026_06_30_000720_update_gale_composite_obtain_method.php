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

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_GALE_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で精霊樹の繊維、精霊王の絹糸、天空の羽布、天界の羽根、天空竜の織布から錬成します。',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_GALE_COMPOSITE')
            ->update([
                'obtain_method' => '素材交換所で精霊樹の繊維、精霊王の絹糸、天空の羽布、天界の羽根から錬成します。',
                'updated_at' => now(),
            ]);
    }
};
