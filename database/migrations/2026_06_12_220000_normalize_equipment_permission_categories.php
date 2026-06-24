<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $weaponMap = [
            'SWORD' => 'sword',
            'DAGGER' => 'dagger',
            'SPEAR' => 'spear',
            'AXE' => 'axe',
            'CLUB' => 'axe',
            'BOW' => 'bow',
            'STAFF' => 'staff',
            'GRIMOIRE' => 'magic_device',
            'FIST' => 'fist',
            'GUN' => 'gun',
        ];

        foreach ($weaponMap as $familyId => $category) {
            DB::table('items')
                ->where('type', 'weapon')
                ->where('weapon_family_id', $familyId)
                ->update(['weapon_category' => $category, 'updated_at' => now()]);
        }

        $armorMap = [
            'light_armor' => 'light_armor',
            'heavy_armor' => 'heavy_armor',
            'robe' => 'robe',
            'arcane_armor' => 'robe',
            'holy_vestment' => 'robe',
            'traveler_wear' => 'clothes',
            'martial_garb' => 'clothes',
            'shadow_garb' => 'cloak',
        ];

        foreach ($armorMap as $familyId => $category) {
            DB::table('items')
                ->where('type', 'armor')
                ->where('armor_family_id', $familyId)
                ->update(['armor_category' => $category, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // 正規化のみのため戻し処理は行わない。
    }
};
