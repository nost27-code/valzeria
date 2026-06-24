<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('characters', 'exploration_cooldown_until')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->timestamp('exploration_cooldown_until')->nullable()->after('last_battle_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('characters', 'exploration_cooldown_until')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->dropColumn('exploration_cooldown_until');
            });
        }
    }
};
