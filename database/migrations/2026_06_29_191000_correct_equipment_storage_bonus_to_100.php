<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_EXPAND_AMOUNT = 500;
    private const CORRECT_EXPAND_AMOUNT = 100;

    public function up(): void
    {
        if (
            !Schema::hasTable('characters')
            || !Schema::hasTable('character_shop_limits')
            || !Schema::hasColumn('characters', 'equipment_storage_limit')
        ) {
            return;
        }

        $overGranted = self::PREVIOUS_EXPAND_AMOUNT - self::CORRECT_EXPAND_AMOUNT;
        if ($overGranted <= 0) {
            return;
        }

        DB::table('character_shop_limits')
            ->select(['character_id', 'purchased_count'])
            ->where('shop_item_key', 'equipment_storage_expand')
            ->whereNull('limit_date')
            ->where('purchased_count', '>', 0)
            ->orderBy('character_id')
            ->chunkById(200, function ($rows) use ($overGranted): void {
                foreach ($rows as $row) {
                    $correction = $overGranted * (int) $row->purchased_count;
                    if ($correction <= 0) {
                        continue;
                    }

                    DB::table('characters')
                        ->where('id', $row->character_id)
                        ->decrement('equipment_storage_limit', $correction);
                }
            }, 'character_id');
    }

    public function down(): void
    {
        // 補正migrationのため、ロールバックでは倉庫上限を再加算しません。
    }
};
