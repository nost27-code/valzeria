<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('character_exploration_states', 'last_treasure_band')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            $table->unsignedInteger('last_treasure_band')->default(0)->after('danger_rate');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('character_exploration_states', 'last_treasure_band')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            $table->dropColumn('last_treasure_band');
        });
    }
};
