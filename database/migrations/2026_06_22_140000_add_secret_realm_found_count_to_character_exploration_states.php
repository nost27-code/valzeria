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
            if (!Schema::hasColumn('character_exploration_states', 'secret_realm_found_count')) {
                $table->unsignedInteger('secret_realm_found_count')->default(0)->after('treasure_found_count');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('character_exploration_states')) {
            return;
        }

        Schema::table('character_exploration_states', function (Blueprint $table) {
            if (Schema::hasColumn('character_exploration_states', 'secret_realm_found_count')) {
                $table->dropColumn('secret_realm_found_count');
            }
        });
    }
};
