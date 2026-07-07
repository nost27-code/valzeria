<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_BASE_BONUS = 100;
    private const OLD_EXPAND_AMOUNT = 100;
    private const NEW_EXPAND_AMOUNT = 300;
    private const NEW_DEFAULT_LIMIT = 300;

    public function up(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'equipment_storage_limit')) {
            return;
        }

        DB::table('characters')
            ->whereNotNull('equipment_storage_limit')
            ->increment('equipment_storage_limit', self::DEFAULT_BASE_BONUS);

        DB::table('characters')
            ->whereNull('equipment_storage_limit')
            ->update(['equipment_storage_limit' => self::NEW_DEFAULT_LIMIT]);

        $this->grantExpansionDifference();
        $this->updateColumnDefault();
    }

    public function down(): void
    {
        // 倉庫上限を戻すと所持品超過を起こすため、ロールバックでは減算しません。
    }

    private function grantExpansionDifference(): void
    {
        if (!Schema::hasTable('character_shop_limits')) {
            return;
        }

        $difference = self::NEW_EXPAND_AMOUNT - self::OLD_EXPAND_AMOUNT;
        if ($difference <= 0) {
            return;
        }

        DB::table('character_shop_limits')
            ->select(['id', 'character_id', 'purchased_count'])
            ->where('shop_item_key', 'equipment_storage_expand')
            ->whereNull('limit_date')
            ->where('purchased_count', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($difference): void {
                foreach ($rows as $row) {
                    $bonus = $difference * (int) $row->purchased_count;
                    if ($bonus <= 0) {
                        continue;
                    }

                    DB::table('characters')
                        ->where('id', $row->character_id)
                        ->increment('equipment_storage_limit', $bonus);
                }
            });
    }

    private function updateColumnDefault(): void
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE characters MODIFY equipment_storage_limit INT UNSIGNED NOT NULL DEFAULT ' . self::NEW_DEFAULT_LIMIT);
    }
};
