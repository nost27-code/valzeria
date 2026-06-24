<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_notifications')
            || ! Schema::hasColumn('character_notifications', 'category')) {
            return;
        }

        DB::table('character_notifications')
            ->whereIn('type', ['market_sale', 'market_material_sold', 'market_listing_expired'])
            ->update([
                'category' => 'market',
                'type' => DB::raw("CASE WHEN type = 'market_sale' THEN 'market_material_sold' ELSE type END"),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('character_notifications')
            || ! Schema::hasColumn('character_notifications', 'category')) {
            return;
        }

        DB::table('character_notifications')
            ->where('type', 'market_material_sold')
            ->update(['type' => 'market_sale']);
    }
};
