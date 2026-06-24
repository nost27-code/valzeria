<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MATERIAL_NAME_BY_CODE = [
        'MAT_WEAPON_FRAGMENT' => '装備の欠片',
        'MAT_WEAPON_CRYSTAL' => '武器の結晶',
        'MAT_WEAPON_CORE' => '武器の核',
        'MAT_ANCIENT_PART' => '古代武器片',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        foreach (self::MATERIAL_NAME_BY_CODE as $code => $name) {
            DB::table('materials')
                ->where('material_code', $code)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Name cleanup only; keep the unified wording.
    }
};
