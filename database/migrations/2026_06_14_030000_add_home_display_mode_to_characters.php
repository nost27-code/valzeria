<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('characters') || Schema::hasColumn('characters', 'home_display_mode')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $column = $table->string('home_display_mode', 16)->default('normal');
            if (Schema::hasColumn('characters', 'beginner_mission_reward_claimed')) {
                $column->after('beginner_mission_reward_claimed');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'home_display_mode')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('home_display_mode');
        });
    }
};
