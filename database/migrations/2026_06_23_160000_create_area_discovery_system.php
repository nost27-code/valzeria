<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            if (!Schema::hasColumn('areas', 'area_kind')) {
                $table->string('area_kind')->default('dungeon')->after('background_image');
            }
            if (!Schema::hasColumn('areas', 'clear_condition_type')) {
                $table->string('clear_condition_type')->default('boss_defeated')->after('area_kind');
            }
            if (!Schema::hasColumn('areas', 'development_required_point')) {
                $table->unsignedSmallInteger('development_required_point')->default(100)->after('clear_condition_type');
            }
            if (!Schema::hasColumn('areas', 'is_route_area')) {
                $table->boolean('is_route_area')->default(false)->after('development_required_point');
            }
        });

        Schema::table('character_area_progresses', function (Blueprint $table) {
            if (!Schema::hasColumn('character_area_progresses', 'development_point')) {
                $table->unsignedSmallInteger('development_point')->default(0)->after('boss_defeated');
            }
            if (!Schema::hasColumn('character_area_progresses', 'discovery_state')) {
                $table->string('discovery_state')->default('undiscovered')->after('development_point');
            }
            if (!Schema::hasColumn('character_area_progresses', 'rumored_at')) {
                $table->dateTime('rumored_at')->nullable()->after('discovery_state');
            }
            if (!Schema::hasColumn('character_area_progresses', 'discovered_at')) {
                $table->dateTime('discovered_at')->nullable()->after('rumored_at');
            }
            if (!Schema::hasColumn('character_area_progresses', 'cleared_at')) {
                $table->dateTime('cleared_at')->nullable()->after('discovered_at');
            }
        });

        if (!Schema::hasTable('character_city_discoveries')) {
            Schema::create('character_city_discoveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('city_id')->constrained()->cascadeOnDelete();
                $table->string('discovery_state')->default('discovered');
                $table->dateTime('rumored_at')->nullable();
                $table->dateTime('discovered_at')->nullable();
                $table->timestamps();

                $table->unique(['character_id', 'city_id']);
            });
        }

        if (!Schema::hasTable('area_discovery_links')) {
            Schema::create('area_discovery_links', function (Blueprint $table) {
                $table->id();
                $table->string('from_type');
                $table->unsignedBigInteger('from_id');
                $table->string('to_type');
                $table->unsignedBigInteger('to_id');
                $table->string('condition_type');
                $table->unsignedSmallInteger('required_development_point')->nullable();
                $table->boolean('requires_boss_defeated')->default(false);
                $table->string('rumor_text')->nullable();
                $table->string('implementation_phase')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['from_type', 'from_id', 'to_type', 'to_id'], 'area_discovery_links_unique_path');
                $table->index(['from_type', 'from_id']);
                $table->index(['to_type', 'to_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('area_discovery_links');
        Schema::dropIfExists('character_city_discoveries');

        Schema::table('character_area_progresses', function (Blueprint $table) {
            foreach (['cleared_at', 'discovered_at', 'rumored_at', 'discovery_state', 'development_point'] as $column) {
                if (Schema::hasColumn('character_area_progresses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('areas', function (Blueprint $table) {
            foreach (['is_route_area', 'development_required_point', 'clear_condition_type', 'area_kind'] as $column) {
                if (Schema::hasColumn('areas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
