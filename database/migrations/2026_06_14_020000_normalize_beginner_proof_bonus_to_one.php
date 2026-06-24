<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REWARD_EXTERNAL_ID = 'BEGINNER_MISSION_PROOF';

    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $query = DB::table('items')
            ->where('name', '初心者の証')
            ->where('type', 'accessory');

        if (Schema::hasColumn('items', 'external_item_id')) {
            $query->orWhere('external_item_id', self::REWARD_EXTERNAL_ID);
        }

        $query->update([
            'hp_bonus' => 1,
            'mp_bonus' => 1,
            'str_bonus' => 1,
            'def_bonus' => 1,
            'agi_bonus' => 1,
            'mag_bonus' => 1,
            'spr_bonus' => 1,
            'luk_bonus' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Balance normalization only. Do not restore the old +3 values.
    }
};
