<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_egg_listings')) return;
        if (Schema::hasIndex('shop_egg_listings', 'shop_egg_listings_player_valmon_egg_id_unique')) {
            Schema::table('shop_egg_listings', fn (Blueprint $table) => $table->dropUnique('shop_egg_listings_player_valmon_egg_id_unique'));
        }
        if (! Schema::hasIndex('shop_egg_listings', 'shop_egg_egg_idx')) {
            Schema::table('shop_egg_listings', fn (Blueprint $table) => $table->index('player_valmon_egg_id', 'shop_egg_egg_idx'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shop_egg_listings')) return;
        if (Schema::hasIndex('shop_egg_listings', 'shop_egg_egg_idx')) {
            Schema::table('shop_egg_listings', fn (Blueprint $table) => $table->dropIndex('shop_egg_egg_idx'));
        }
    }
};
