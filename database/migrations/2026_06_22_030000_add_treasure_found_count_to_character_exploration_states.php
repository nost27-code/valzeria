<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('character_exploration_states', 'treasure_found_count')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            $table->unsignedInteger('treasure_found_count')->default(0)->after('last_treasure_band');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('character_exploration_states', 'treasure_found_count')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            $table->dropColumn('treasure_found_count');
        });
    }
};
