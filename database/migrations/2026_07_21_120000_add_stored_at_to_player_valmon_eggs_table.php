<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_valmon_eggs', function (Blueprint $table) {
            $table->timestamp('stored_at')->nullable()->after('found_at');
            $table->index(['character_id', 'stored_at'], 'player_valmon_eggs_character_stored_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('player_valmon_eggs', function (Blueprint $table) {
            $table->dropIndex('player_valmon_eggs_character_stored_at_index');
            $table->dropColumn('stored_at');
        });
    }
};
