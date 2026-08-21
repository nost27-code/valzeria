<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('six_hero_seasons', 'ranking_initialized_at')) {
            Schema::table('six_hero_seasons', function (Blueprint $table): void {
                $table->dateTime('ranking_initialized_at')->nullable();
            });
        }

        if (! Schema::hasIndex(
            'six_hero_seasons',
            'six_hero_seasons_ranking_initialized_at_idx',
        )) {
            Schema::table('six_hero_seasons', function (Blueprint $table): void {
                $table->index(
                    'ranking_initialized_at',
                    'six_hero_seasons_ranking_initialized_at_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(
            'six_hero_seasons',
            'six_hero_seasons_ranking_initialized_at_idx',
        )) {
            Schema::table('six_hero_seasons', function (Blueprint $table): void {
                $table->dropIndex('six_hero_seasons_ranking_initialized_at_idx');
            });
        }

        if (Schema::hasColumn('six_hero_seasons', 'ranking_initialized_at')) {
            Schema::table('six_hero_seasons', function (Blueprint $table): void {
                $table->dropColumn('ranking_initialized_at');
            });
        }
    }
};
