<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                if (!Schema::hasColumn('materials', 'market_category')) {
                    $table->string('market_category')->default('normal')->after('is_tradable');
                }
                if (!Schema::hasColumn('materials', 'trade_policy')) {
                    $table->string('trade_policy')->default('marketable')->after('market_category');
                }
                if (!Schema::hasColumn('materials', 'npc_sell_price')) {
                    $table->integer('npc_sell_price')->default(0)->after('trade_policy');
                }
                if (!Schema::hasColumn('materials', 'market_min_price')) {
                    $table->integer('market_min_price')->nullable()->after('npc_sell_price');
                }
                if (!Schema::hasColumn('materials', 'market_max_price')) {
                    $table->integer('market_max_price')->nullable()->after('market_min_price');
                }
                if (!Schema::hasColumn('materials', 'source_area_id')) {
                    $table->unsignedBigInteger('source_area_id')->nullable()->after('market_max_price');
                }
                if (!Schema::hasColumn('materials', 'is_key_item')) {
                    $table->boolean('is_key_item')->default(false)->after('source_area_id');
                }
                if (!Schema::hasColumn('materials', 'is_cash_item')) {
                    $table->boolean('is_cash_item')->default(false)->after('is_key_item');
                }
            });

            $this->initializeMaterialMarketPolicy();
        }

        if (!Schema::hasTable('market_listings')) {
            Schema::create('market_listings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_character_id');
                $table->string('listing_type')->default('material');
                $table->unsignedBigInteger('material_id')->nullable();
                $table->integer('quantity');
                $table->integer('remaining_quantity');
                $table->integer('unit_price');
                $table->integer('listing_fee')->default(0);
                $table->string('status')->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['material_id', 'status', 'unit_price', 'created_at'], 'market_listings_material_buy_idx');
                $table->index(['seller_character_id', 'status'], 'market_listings_seller_status_idx');
                $table->index('expires_at');
            });
        }

        if (!Schema::hasTable('market_transactions')) {
            Schema::create('market_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('listing_id');
                $table->unsignedBigInteger('seller_character_id');
                $table->unsignedBigInteger('buyer_character_id');
                $table->string('listing_type')->default('material');
                $table->unsignedBigInteger('material_id')->nullable();
                $table->integer('quantity');
                $table->integer('unit_price');
                $table->integer('total_price');
                $table->integer('sale_fee')->default(0);
                $table->integer('seller_received');
                $table->timestamp('created_at')->nullable();

                $table->index(['seller_character_id', 'created_at'], 'market_transactions_seller_idx');
                $table->index(['buyer_character_id', 'created_at'], 'market_transactions_buyer_idx');
                $table->index(['material_id', 'created_at'], 'market_transactions_material_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_transactions');
        Schema::dropIfExists('market_listings');

        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                foreach ([
                    'market_category',
                    'trade_policy',
                    'npc_sell_price',
                    'market_min_price',
                    'market_max_price',
                    'source_area_id',
                    'is_key_item',
                    'is_cash_item',
                ] as $column) {
                    if (Schema::hasColumn('materials', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function initializeMaterialMarketPolicy(): void
    {
        $now = now();

        DB::table('materials')->update([
            'market_category' => 'normal',
            'trade_policy' => 'unmarketable',
            'npc_sell_price' => DB::raw('COALESCE(npc_sale_price, 0)'),
            'market_min_price' => null,
            'market_max_price' => null,
            'updated_at' => $now,
        ]);

        DB::table('materials')
            ->where('is_tradable', true)
            ->where('material_type', 'common_drop')
            ->update([
                'market_category' => 'normal',
                'trade_policy' => 'marketable',
                'market_min_price' => DB::raw('CASE WHEN COALESCE(npc_sale_price, 0) > 1 THEN npc_sale_price ELSE 1 END'),
                'market_max_price' => DB::raw('CASE WHEN COALESCE(npc_sale_price, 1) * 20 > 20 THEN COALESCE(npc_sale_price, 1) * 20 ELSE 20 END'),
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('is_tradable', true)
            ->where('material_type', 'regional_drop')
            ->update([
                'market_category' => 'regional',
                'trade_policy' => 'marketable',
                'market_min_price' => DB::raw('CASE WHEN COALESCE(npc_sale_price, 0) > 1 THEN npc_sale_price ELSE 1 END'),
                'market_max_price' => DB::raw('CASE WHEN COALESCE(npc_sale_price, 1) * 50 > 50 THEN COALESCE(npc_sale_price, 1) * 50 ELSE 50 END'),
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where(function ($query) {
                $query->where('is_tradable', false)
                    ->orWhereIn('material_type', [
                        'boss_unique',
                        'branch_evolution',
                        'brewing',
                        'champ',
                        'enhance',
                        'evolution_stone',
                        'exchange_ticket',
                        'sell_treasure',
                        'token',
                        'weapon_unlock_key',
                    ])
                    ->orWhere('category', 'like', '%討伐証%')
                    ->orWhere('category', 'like', '%刻印%')
                    ->orWhere('main_use', 'like', '%解放キー%')
                    ->orWhere('name', 'like', '%刻印')
                    ->orWhere('name', 'like', '%王印')
                    ->orWhere('name', 'like', '%神印');
            })
            ->update([
                'trade_policy' => 'unmarketable',
                'is_key_item' => true,
                'market_min_price' => null,
                'market_max_price' => null,
                'updated_at' => $now,
            ]);
    }
};
