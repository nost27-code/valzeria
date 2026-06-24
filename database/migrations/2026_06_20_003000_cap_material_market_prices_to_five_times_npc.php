<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasColumn('materials', 'market_max_price')) {
            return;
        }

        DB::table('materials')
            ->where('trade_policy', 'marketable')
            ->where('is_tradable', true)
            ->update([
                'market_min_price' => DB::raw('CASE WHEN COALESCE(npc_sell_price, npc_sale_price, 0) > 1 THEN COALESCE(npc_sell_price, npc_sale_price, 0) ELSE 1 END'),
                'market_max_price' => DB::raw('CASE WHEN COALESCE(npc_sell_price, npc_sale_price, 1) * 5 > 5 THEN COALESCE(npc_sell_price, npc_sale_price, 1) * 5 ELSE 5 END'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasColumn('materials', 'market_max_price')) {
            return;
        }

        DB::table('materials')
            ->where('trade_policy', 'marketable')
            ->where('is_tradable', true)
            ->update([
                'market_max_price' => DB::raw("
                    CASE
                        WHEN market_category = 'regional' THEN
                            CASE WHEN COALESCE(npc_sell_price, npc_sale_price, 1) * 50 > 50 THEN COALESCE(npc_sell_price, npc_sale_price, 1) * 50 ELSE 50 END
                        ELSE
                            CASE WHEN COALESCE(npc_sell_price, npc_sale_price, 1) * 20 > 20 THEN COALESCE(npc_sell_price, npc_sale_price, 1) * 20 ELSE 20 END
                    END
                "),
                'updated_at' => now(),
            ]);
    }
};
