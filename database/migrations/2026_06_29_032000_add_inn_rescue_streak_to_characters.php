<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('characters') || Schema::hasColumn('characters', 'inn_rescue_streak')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('inn_rescue_streak')->default(0)->after('exploration_cooldown_until');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'inn_rescue_streak')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('inn_rescue_streak');
        });
    }
};
