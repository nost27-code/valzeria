<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_exploration_states')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            if (!Schema::hasColumn('character_exploration_states', 'depth_tier')) {
                $table->string('depth_tier', 32)->default('surface')->after('danger_rate');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('character_exploration_states')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            if (Schema::hasColumn('character_exploration_states', 'depth_tier')) {
                $table->dropColumn('depth_tier');
            }
        });
    }
};
