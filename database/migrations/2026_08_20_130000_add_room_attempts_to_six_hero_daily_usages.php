<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('six_hero_daily_usages')
            || Schema::hasColumn('six_hero_daily_usages', 'official_attempts_by_room')
        ) {
            return;
        }

        Schema::table('six_hero_daily_usages', function (Blueprint $table): void {
            $table->json('official_attempts_by_room')
                ->nullable()
                ->after('official_attempts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('six_hero_daily_usages')
            || ! Schema::hasColumn('six_hero_daily_usages', 'official_attempts_by_room')
        ) {
            return;
        }

        Schema::table('six_hero_daily_usages', function (Blueprint $table): void {
            $table->dropColumn('official_attempts_by_room');
        });
    }
};
