<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('valmon_masters')) {
            Schema::create('valmon_masters', function (Blueprint $table) {
                $table->id();
                $table->string('valmon_key', 50)->unique();
                $table->string('name', 50);
                $table->text('description')->nullable();
                $table->string('silhouette_type', 50)->nullable();
                $table->string('rarity', 20)->default('normal');
                $table->string('base_find_material_category', 50)->nullable();
                $table->boolean('is_starter')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('valmon_spawn_regions')) {
            Schema::create('valmon_spawn_regions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('valmon_master_id')->constrained('valmon_masters')->cascadeOnDelete();
                $table->foreignId('city_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('spawn_weight')->default(1000);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['valmon_master_id', 'city_id'], 'valmon_spawn_unique');
            });
        }

        if (!Schema::hasTable('player_valmons')) {
            Schema::create('player_valmons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('valmon_master_id')->constrained('valmon_masters')->cascadeOnDelete();
                $table->string('nickname', 50)->nullable();
                $table->unsignedInteger('level')->default(1);
                $table->unsignedBigInteger('exp')->default(0);
                $table->unsignedTinyInteger('affection')->default(0);
                $table->string('evolution_stage', 20)->default('child');
                $table->boolean('is_partner')->default(false);
                $table->string('obtained_source', 30)->default('egg');
                $table->timestamp('obtained_at')->nullable();
                $table->timestamps();

                $table->index(['character_id', 'is_partner']);
            });
        }

        if (!Schema::hasTable('player_valmon_eggs')) {
            Schema::create('player_valmon_eggs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('valmon_master_id')->constrained('valmon_masters')->cascadeOnDelete();
                $table->foreignId('found_city_id')->nullable()->constrained('cities')->nullOnDelete();
                $table->foreignId('found_area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('found_exploration_state_id')->nullable()->constrained('character_exploration_states')->nullOnDelete();
                $table->boolean('is_hatched')->default(false);
                $table->boolean('is_lost')->default(false);
                $table->timestamp('found_at')->nullable();
                $table->timestamp('hatched_at')->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->timestamps();

                $table->index(['character_id', 'is_hatched', 'is_lost']);
                $table->index(['character_id', 'found_at']);
            });
        }

        if (!Schema::hasTable('valmon_feed_logs')) {
            Schema::create('valmon_feed_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('player_valmon_id')->constrained('player_valmons')->cascadeOnDelete();
                $table->string('feed_type', 50);
                $table->unsignedBigInteger('feed_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedBigInteger('gained_exp')->default(0);
                $table->unsignedInteger('gained_affection')->default(0);
                $table->timestamps();

                $table->index(['character_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('valmon_material_find_logs')) {
            Schema::create('valmon_material_find_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('player_valmon_id')->constrained('player_valmons')->cascadeOnDelete();
                $table->foreignId('character_exploration_state_id')->nullable()->constrained('character_exploration_states')->nullOnDelete();
                $table->foreignId('material_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();

                $table->index(['character_id', 'created_at']);
            });
        }

        if (Schema::hasTable('character_exploration_states')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                if (!Schema::hasColumn('character_exploration_states', 'valmon_material_found')) {
                    $table->boolean('valmon_material_found')->default(false)->after('dungeon_lord_encountered');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('character_exploration_states') && Schema::hasColumn('character_exploration_states', 'valmon_material_found')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                $table->dropColumn('valmon_material_found');
            });
        }

        Schema::dropIfExists('valmon_material_find_logs');
        Schema::dropIfExists('valmon_feed_logs');
        Schema::dropIfExists('player_valmon_eggs');
        Schema::dropIfExists('player_valmons');
        Schema::dropIfExists('valmon_spawn_regions');
        Schema::dropIfExists('valmon_masters');
    }
};
