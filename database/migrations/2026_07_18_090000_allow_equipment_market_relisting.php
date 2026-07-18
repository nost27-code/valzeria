<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_market_listings', function (Blueprint $table) {
            $table->dropUnique('equipment_market_listings_character_item_id_unique');
            $table->index('character_item_id', 'equipment_market_listings_character_item_idx');
        });
    }

    public function down(): void
    {
        $hasRelistingHistory = DB::table('equipment_market_listings')
            ->select('character_item_id')
            ->groupBy('character_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRelistingHistory) {
            throw new \RuntimeException('装備市場の再出品履歴があるため、このmigrationは安全にロールバックできません。');
        }

        Schema::table('equipment_market_listings', function (Blueprint $table) {
            $table->dropIndex('equipment_market_listings_character_item_idx');
            $table->unique('character_item_id');
        });
    }
};
