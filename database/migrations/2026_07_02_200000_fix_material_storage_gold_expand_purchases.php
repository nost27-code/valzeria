<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ITEM_KEY = 'material_storage_gold_expand';
    private const EXPAND_AMOUNT = 50;

    public function up(): void
    {
        if (
            !Schema::hasTable('characters')
            || !Schema::hasTable('shop_purchase_logs')
            || !Schema::hasTable('character_shop_limits')
            || !Schema::hasColumn('characters', 'material_storage_limit')
        ) {
            return;
        }

        DB::table('shop_purchase_logs')
            ->select('character_id', DB::raw('COUNT(*) as purchase_count'))
            ->where('shop_item_key', self::ITEM_KEY)
            ->groupBy('character_id')
            ->orderBy('character_id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $characterId = (int) $row->character_id;
                    $loggedCount = (int) $row->purchase_count;
                    if ($characterId <= 0 || $loggedCount <= 0) {
                        continue;
                    }

                    $recordedCount = (int) DB::table('character_shop_limits')
                        ->where('character_id', $characterId)
                        ->where('shop_item_key', self::ITEM_KEY)
                        ->whereNull('limit_date')
                        ->sum('purchased_count');

                    $missingCount = max(0, $loggedCount - $recordedCount);
                    if ($missingCount <= 0) {
                        continue;
                    }

                    DB::table('characters')
                        ->where('id', $characterId)
                        ->increment('material_storage_limit', $missingCount * self::EXPAND_AMOUNT);

                    $limit = DB::table('character_shop_limits')
                        ->where('character_id', $characterId)
                        ->where('shop_item_key', self::ITEM_KEY)
                        ->whereNull('limit_date')
                        ->orderBy('id')
                        ->first();

                    if ($limit) {
                        DB::table('character_shop_limits')
                            ->where('id', $limit->id)
                            ->increment('purchased_count', $missingCount);
                    } else {
                        DB::table('character_shop_limits')->insert([
                            'character_id' => $characterId,
                            'shop_item_key' => self::ITEM_KEY,
                            'limit_date' => null,
                            'purchased_count' => $missingCount,
                            'used_count' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // 購入済みGold拡張の補填を戻すと倉庫上限不足を起こすため、ロールバックでは値を戻しません。
    }
};
