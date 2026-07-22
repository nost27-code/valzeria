<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('player_shops')) {
            Schema::create('player_shops', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('character_id')->unique();
                $table->string('name', 20);
                $table->string('description', 100)->default('商品を販売しています。');
                $table->string('shop_type', 32)->default('general');
                $table->string('icon_key', 40)->default('general');
                $table->string('banner_key', 40)->default('default');
                $table->string('status', 20)->default('open');
                $table->timestamp('name_changed_at')->nullable();
                $table->timestamp('last_stocked_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'last_stocked_at'], 'player_shops_status_stocked_idx');
            });
        }

        foreach (['market_listings' => 'market_listings_shop_status_idx', 'equipment_market_listings' => 'equipment_market_listings_shop_status_idx'] as $tableName => $indexName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'shop_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->unsignedBigInteger('shop_id')->nullable()->after('seller_character_id');
                });
            }
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'shop_id') && ! Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->index(['shop_id', 'status'], $indexName);
                });
            }
        }

        if (! Schema::hasTable('shop_egg_listings')) {
            Schema::create('shop_egg_listings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shop_id');
                $table->unsignedBigInteger('seller_character_id');
                $table->unsignedBigInteger('buyer_character_id')->nullable();
                $table->unsignedBigInteger('player_valmon_egg_id');
                $table->unsignedBigInteger('valmon_master_id');
                $table->string('display_name_snapshot');
                $table->unsignedBigInteger('listing_price');
                $table->string('status', 20)->default('active');
                $table->timestamp('expires_at');
                $table->timestamp('sold_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->index(['shop_id', 'status'], 'shop_egg_shop_status_idx');
                $table->index('player_valmon_egg_id', 'shop_egg_egg_idx');
                $table->index(['status', 'listing_price'], 'shop_egg_status_price_idx');
            });
        }

        if (! Schema::hasTable('shop_egg_transactions')) {
            Schema::create('shop_egg_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('listing_id')->unique();
                $table->unsignedBigInteger('shop_id');
                $table->unsignedBigInteger('seller_character_id');
                $table->unsignedBigInteger('buyer_character_id');
                $table->unsignedBigInteger('player_valmon_egg_id');
                $table->unsignedBigInteger('sale_price');
                $table->timestamp('sold_at');
                $table->timestamps();
                $table->index(['shop_id', 'sold_at'], 'shop_egg_transactions_shop_sold_idx');
            });
        }

        if (! Schema::hasTable('shop_favorites')) {
            Schema::create('shop_favorites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shop_id');
                $table->unsignedBigInteger('character_id');
                $table->timestamps();
                $table->unique(['shop_id', 'character_id'], 'shop_favorites_unique');
            });
        }

        $sellerIds = [];
        foreach (['market_listings', 'equipment_market_listings'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                $sellerIds = array_merge($sellerIds, DB::table($tableName)->whereNotNull('seller_character_id')->pluck('seller_character_id')->all());
            }
        }
        $sellerIds = array_values(array_unique(array_map('intval', $sellerIds)));
        if ($sellerIds !== [] && Schema::hasTable('characters')) {
            DB::table('characters')->whereIn('id', $sellerIds)->orderBy('id')->chunkById(200, function ($characters) {
                foreach ($characters as $character) {
                    $name = Str::limit((string) $character->name, 18, '') . '商店';
                    DB::table('player_shops')->insertOrIgnore([
                        'character_id' => $character->id,
                        'name' => $name,
                        'description' => '商品を販売しています。',
                        'shop_type' => 'general',
                        'icon_key' => 'general',
                        'banner_key' => 'default',
                        'status' => 'open',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        DB::table('player_shops')->orderBy('id')->chunkById(200, function ($shops) {
            foreach ($shops as $shop) {
                foreach (['market_listings', 'equipment_market_listings'] as $tableName) {
                    if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'shop_id')) {
                        DB::table($tableName)->whereNull('shop_id')->where('seller_character_id', $shop->character_id)->update(['shop_id' => $shop->id]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_favorites');
        Schema::dropIfExists('shop_egg_transactions');
        Schema::dropIfExists('shop_egg_listings');
        foreach (['market_listings' => 'market_listings_shop_status_idx', 'equipment_market_listings' => 'equipment_market_listings_shop_status_idx'] as $tableName => $indexName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'shop_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                    $table->dropColumn('shop_id');
                });
            }
        }
        Schema::dropIfExists('player_shops');
    }
};
