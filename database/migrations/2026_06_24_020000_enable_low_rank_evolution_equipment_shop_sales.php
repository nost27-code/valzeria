<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RANK_SALES = [
        'G' => ['city_id' => 1, 'price' => 360],
        'F' => ['city_id' => 2, 'price' => 900],
        'E' => ['city_id' => 3, 'price' => 1800],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        foreach (self::RANK_SALES as $rank => $sale) {
            $this->enableRankForShop('weapon', 'weapon_rank', $rank, (int) $sale['city_id'], (int) $sale['price']);
            $this->enableRankForShop('armor', 'armor_rank', $rank, (int) $sale['city_id'], (int) $sale['price']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        foreach (self::RANK_SALES as $rank => $_sale) {
            DB::table('items')
                ->where('type', 'weapon')
                ->where('weapon_rank', $rank)
                ->whereNotNull('next_item_external_id')
                ->update([
                    'is_shop_item' => false,
                    'unlock_city_id' => null,
                    'price' => 0,
                    'sell_price' => 0,
                    'updated_at' => now(),
                ]);

            DB::table('items')
                ->where('type', 'armor')
                ->where('armor_rank', $rank)
                ->whereNotNull('next_item_external_id')
                ->update([
                    'is_shop_item' => false,
                    'unlock_city_id' => null,
                    'price' => 0,
                    'sell_price' => 0,
                    'updated_at' => now(),
                ]);
        }
    }

    private function enableRankForShop(string $type, string $rankColumn, string $rank, int $cityId, int $fallbackPrice): void
    {
        DB::table('items')
            ->where('type', $type)
            ->where($rankColumn, $rank)
            ->whereNotNull('next_item_external_id')
            ->orderBy('id')
            ->get(['id', 'price'])
            ->each(function ($item) use ($cityId, $fallbackPrice): void {
                $price = max($fallbackPrice, (int) ($item->price ?? 0));

                DB::table('items')
                    ->where('id', $item->id)
                    ->update([
                        'is_shop_item' => true,
                        'is_active' => true,
                        'unlock_city_id' => $cityId,
                        'price' => $price,
                        'sell_price' => max(1, (int) floor($price * 0.2)),
                        'updated_at' => now(),
                    ]);
            });
    }
};
