<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('character_exploration_states', 'danger_rate')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE character_exploration_states MODIFY danger_rate INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('character_exploration_states', 'danger_rate')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE character_exploration_states MODIFY danger_rate TINYINT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
