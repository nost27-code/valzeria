<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('npc_procurement_request_templates')) {
            Schema::table('npc_procurement_request_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('npc_procurement_request_templates', 'npc_id')) {
                    $table->unsignedInteger('npc_id')->nullable()->after('city_id');
                    $table->index('npc_id', 'npc_req_tpl_npc_idx');
                }
            });
        }

        if (Schema::hasTable('npc_procurement_requests')) {
            Schema::table('npc_procurement_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('npc_procurement_requests', 'npc_id')) {
                    $table->unsignedInteger('npc_id')->nullable()->after('city_id');
                    $table->index(['npc_id', 'status'], 'npc_requests_npc_status_idx');
                }
            });
        }

        if (! Schema::hasTable('npc_material_stocks')) {
            Schema::create('npc_material_stocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('npc_id');
                $table->unsignedBigInteger('material_id');
                $table->integer('quantity')->default(0);
                $table->timestamp('last_received_at')->nullable();
                $table->timestamps();

                $table->unique(['npc_id', 'material_id'], 'npc_material_stocks_unique');
                $table->index(['material_id', 'quantity'], 'npc_material_stocks_material_qty_idx');
            });
        }

        if (Schema::hasTable('market_listings')) {
            Schema::table('market_listings', function (Blueprint $table) {
                if (! Schema::hasColumn('market_listings', 'seller_type')) {
                    $table->string('seller_type', 20)->default('character')->after('seller_character_id');
                }
                if (! Schema::hasColumn('market_listings', 'seller_npc_id')) {
                    $table->unsignedInteger('seller_npc_id')->nullable()->after('seller_type');
                }
            });

            DB::table('market_listings')
                ->whereNull('seller_type')
                ->update(['seller_type' => 'character']);

            Schema::table('market_listings', function (Blueprint $table) {
                $table->index(['seller_type', 'seller_npc_id', 'status'], 'market_listings_seller_type_idx');
            });
        }

        if (Schema::hasTable('market_transactions')) {
            Schema::table('market_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('market_transactions', 'seller_type')) {
                    $table->string('seller_type', 20)->default('character')->after('seller_character_id');
                }
                if (! Schema::hasColumn('market_transactions', 'seller_npc_id')) {
                    $table->unsignedInteger('seller_npc_id')->nullable()->after('seller_type');
                }
            });

            DB::table('market_transactions')
                ->whereNull('seller_type')
                ->update(['seller_type' => 'character']);
        }

        $this->backfillNpcProcurementOwners();
    }

    public function down(): void
    {
        if (Schema::hasTable('market_transactions')) {
            Schema::table('market_transactions', function (Blueprint $table) {
                foreach (['seller_npc_id', 'seller_type'] as $column) {
                    if (Schema::hasColumn('market_transactions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('market_listings')) {
            Schema::table('market_listings', function (Blueprint $table) {
                foreach (['seller_npc_id', 'seller_type'] as $column) {
                    if (Schema::hasColumn('market_listings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('npc_material_stocks');

        if (Schema::hasTable('npc_procurement_requests')) {
            Schema::table('npc_procurement_requests', function (Blueprint $table) {
                if (Schema::hasColumn('npc_procurement_requests', 'npc_id')) {
                    $table->dropColumn('npc_id');
                }
            });
        }

        if (Schema::hasTable('npc_procurement_request_templates')) {
            Schema::table('npc_procurement_request_templates', function (Blueprint $table) {
                if (Schema::hasColumn('npc_procurement_request_templates', 'npc_id')) {
                    $table->dropColumn('npc_id');
                }
            });
        }
    }

    private function backfillNpcProcurementOwners(): void
    {
        if (! Schema::hasTable('npc_master') || ! Schema::hasTable('npc_procurement_requests')) {
            return;
        }

        $npcs = DB::table('npc_master')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['npc_id', 'npc_name'])
            ->values();

        if ($npcs->isEmpty()) {
            return;
        }

        DB::table('npc_procurement_requests')
            ->whereNull('npc_id')
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($npcs) {
                foreach ($requests as $request) {
                    $npc = $npcs[((int) $request->id - 1) % $npcs->count()];
                    DB::table('npc_procurement_requests')
                        ->where('id', $request->id)
                        ->update([
                            'npc_id' => $npc->npc_id,
                            'requester_name' => $npc->npc_name,
                            'requester_type' => 'npc',
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
