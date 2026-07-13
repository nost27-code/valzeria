<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'is_tradeable')) {
                $table->boolean('is_tradeable')->default(true)->after('affix_enabled');
                $table->index('is_tradeable');
            }
        });

        Schema::table('character_items', function (Blueprint $table) {
            if (! Schema::hasColumn('character_items', 'affix_prefix_level')) {
                $table->unsignedTinyInteger('affix_prefix_level')->default(0)->after('affix_prefix_id');
            }
            if (! Schema::hasColumn('character_items', 'affix_suffix_level')) {
                $table->unsignedTinyInteger('affix_suffix_level')->default(0)->after('affix_suffix_id');
            }
            if (! Schema::hasColumn('character_items', 'market_listing_id')) {
                $table->unsignedBigInteger('market_listing_id')->nullable()->after('is_locked');
                $table->unique('market_listing_id', 'character_items_market_listing_unique');
            }
            if (! Schema::hasColumn('character_items', 'market_relistable_at')) {
                $table->timestamp('market_relistable_at')->nullable()->after('market_listing_id');
                $table->index('market_relistable_at', 'character_items_market_relistable_idx');
            }
            if (! Schema::hasColumn('character_items', 'is_tradeable')) {
                $table->boolean('is_tradeable')->default(true)->after('market_relistable_at');
                $table->index('is_tradeable', 'character_items_tradeable_idx');
            }
        });

        DB::table('character_items')->whereNotNull('affix_prefix_id')->where('affix_prefix_level', 0)->update(['affix_prefix_level' => 1]);
        DB::table('character_items')->whereNotNull('affix_suffix_id')->where('affix_suffix_level', 0)->update(['affix_suffix_level' => 1]);

        Schema::create('equipment_market_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_character_id');
            $table->unsignedBigInteger('buyer_character_id')->nullable();
            $table->unsignedBigInteger('character_item_id')->unique();
            $table->unsignedBigInteger('item_id');
            $table->string('display_name_snapshot');
            $table->string('item_name_snapshot');
            $table->json('item_snapshot');
            $table->string('weapon_category', 32)->nullable();
            $table->string('weapon_rank', 16)->nullable();
            $table->string('quality_key', 20)->default('normal');
            $table->unsignedTinyInteger('enhance_level')->default(0);
            $table->unsignedBigInteger('engraving_id')->nullable();
            $table->unsignedTinyInteger('engraving_level')->default(0);
            $table->unsignedBigInteger('slayer_type_id')->nullable();
            $table->unsignedTinyInteger('slayer_level')->default(0);
            $table->unsignedBigInteger('appraisal_price');
            $table->unsignedBigInteger('minimum_price');
            $table->unsignedBigInteger('maximum_price');
            $table->unsignedBigInteger('listing_price');
            $table->unsignedSmallInteger('fee_rate_bps');
            $table->unsignedBigInteger('fee_amount')->nullable();
            $table->unsignedBigInteger('seller_proceeds')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'listing_price'], 'equipment_market_status_price_idx');
            $table->index(['status', 'expires_at'], 'equipment_market_status_expiry_idx');
            $table->index(['status', 'weapon_category'], 'equipment_market_status_category_idx');
            $table->index(['status', 'weapon_rank'], 'equipment_market_status_rank_idx');
            $table->index(['status', 'engraving_id', 'engraving_level'], 'equipment_market_status_engraving_idx');
            $table->index(['status', 'slayer_type_id', 'slayer_level'], 'equipment_market_status_slayer_idx');
            $table->index(['seller_character_id', 'status'], 'equipment_market_seller_status_idx');
            $table->index('buyer_character_id', 'equipment_market_buyer_idx');
        });

        Schema::create('equipment_market_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id')->unique();
            $table->unsignedBigInteger('seller_character_id');
            $table->unsignedBigInteger('buyer_character_id');
            $table->unsignedBigInteger('character_item_id');
            $table->unsignedBigInteger('item_id');
            $table->json('item_snapshot');
            $table->unsignedBigInteger('sale_price');
            $table->unsignedSmallInteger('fee_rate_bps');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('seller_proceeds');
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->index(['seller_character_id', 'created_at'], 'equipment_market_tx_seller_idx');
            $table->index(['buyer_character_id', 'created_at'], 'equipment_market_tx_buyer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_market_transactions');
        Schema::dropIfExists('equipment_market_listings');

        Schema::table('character_items', function (Blueprint $table) {
            $table->dropUnique('character_items_market_listing_unique');
            $table->dropIndex('character_items_market_relistable_idx');
            $table->dropIndex('character_items_tradeable_idx');
            foreach (['market_listing_id', 'market_relistable_at', 'is_tradeable', 'affix_prefix_level', 'affix_suffix_level'] as $column) {
                if (Schema::hasColumn('character_items', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'is_tradeable')) { $table->dropIndex(['is_tradeable']); $table->dropColumn('is_tradeable'); }
        });
    }
};
