<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RENAMES = [
        'SHOP_WPN_ARC_GUN' => '衛兵制式小銃',
        'SHOP_WPN_MAR_GUN' => '甲板守りの銃',
        'SHOP_WPN_ELF_CLUB' => '樫守りの棍棒',
        'SHOP_WPN_GRA_GUN' => '工房仕込みの銃',
        'SHOP_WPN_FRO_GUN' => '雪原仕込みの銃',
        'SHOP_WPN_SAN_SPEAR' => '陽炎の槍',
        'SHOP_WPN_SAN_FIST' => '砂殻の拳甲',
        'SHOP_WPN_SAN_MECHGUN' => '砂熱の機工銃',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        foreach (self::RENAMES as $externalItemId => $name) {
            DB::table('items')
                ->where('external_item_id', $externalItemId)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Master-data polish only. Keep current names on rollback.
    }
};
