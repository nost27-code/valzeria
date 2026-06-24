<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (!Schema::hasColumn('characters', 'material_storage_limit')) {
                    $table->unsignedInteger('material_storage_limit')->default(300)->after('free_kiseki');
                }

                if (!Schema::hasColumn('characters', 'equipment_storage_limit')) {
                    $table->unsignedInteger('equipment_storage_limit')->default(200)->after('material_storage_limit');
                }
            });
        }

        if (Schema::hasTable('character_exploration_states')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                if (!Schema::hasColumn('character_exploration_states', 'rescue_insurance_enabled')) {
                    $table->boolean('rescue_insurance_enabled')->default(false)->after('dungeon_lord_encountered');
                }
            });
        }

        if (!Schema::hasTable('shop_purchase_logs')) {
            Schema::create('shop_purchase_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('shop_item_key', 100);
                $table->string('item_name', 100);
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('total_kiseki_cost');
                $table->unsignedInteger('free_kiseki_spent')->default(0);
                $table->unsignedInteger('paid_kiseki_spent')->default(0);
                $table->timestamps();

                $table->index(['character_id', 'shop_item_key']);
            });
        }

        if (!Schema::hasTable('character_shop_limits')) {
            Schema::create('character_shop_limits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('shop_item_key', 100);
                $table->date('limit_date')->nullable();
                $table->unsignedInteger('purchased_count')->default(0);
                $table->unsignedInteger('used_count')->default(0);
                $table->timestamps();

                $table->unique(['character_id', 'shop_item_key', 'limit_date'], 'character_shop_limit_unique');
            });
        }

        if (!Schema::hasTable('character_consumable_items')) {
            Schema::create('character_consumable_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('item_key', 100);
                $table->unsignedInteger('quantity')->default(0);
                $table->timestamps();

                $table->unique(['character_id', 'item_key'], 'character_consumable_item_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_consumable_items');
        Schema::dropIfExists('character_shop_limits');
        Schema::dropIfExists('shop_purchase_logs');

        if (Schema::hasTable('character_exploration_states') && Schema::hasColumn('character_exploration_states', 'rescue_insurance_enabled')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                $table->dropColumn('rescue_insurance_enabled');
            });
        }

        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (Schema::hasColumn('characters', 'equipment_storage_limit')) {
                    $table->dropColumn('equipment_storage_limit');
                }

                if (Schema::hasColumn('characters', 'material_storage_limit')) {
                    $table->dropColumn('material_storage_limit');
                }
            });
        }
    }
};
