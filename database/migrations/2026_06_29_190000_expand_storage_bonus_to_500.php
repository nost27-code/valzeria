<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_EXPAND_AMOUNT = 50;
    private const NEW_EXPAND_AMOUNT = 500;

    public function up(): void
    {
        if (
            !Schema::hasTable('characters')
            || !Schema::hasTable('character_shop_limits')
            || !Schema::hasColumn('characters', 'material_storage_limit')
            || !Schema::hasColumn('characters', 'equipment_storage_limit')
        ) {
            return;
        }

        $difference = self::NEW_EXPAND_AMOUNT - self::OLD_EXPAND_AMOUNT;
        if ($difference <= 0) {
            return;
        }

        $this->grantDifference('material_storage_expand', 'material_storage_limit', $difference);
        $this->grantDifference('equipment_storage_expand', 'equipment_storage_limit', $difference);
    }

    public function down(): void
    {
        // 既存購入者への補填を戻すと倉庫上限不足を起こすため、ロールバックでは値を戻しません。
    }

    private function grantDifference(string $itemKey, string $column, int $difference): void
    {
        DB::table('character_shop_limits')
            ->select(['character_id', 'purchased_count'])
            ->where('shop_item_key', $itemKey)
            ->whereNull('limit_date')
            ->where('purchased_count', '>', 0)
            ->orderBy('character_id')
            ->chunkById(200, function ($rows) use ($column, $difference): void {
                foreach ($rows as $row) {
                    $bonus = $difference * (int) $row->purchased_count;
                    if ($bonus <= 0) {
                        continue;
                    }

                    DB::table('characters')
                        ->where('id', $row->character_id)
                        ->increment($column, $bonus);
                }
            }, 'character_id');
    }
};
