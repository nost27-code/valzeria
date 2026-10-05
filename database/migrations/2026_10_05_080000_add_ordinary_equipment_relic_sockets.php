<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_relics', function (Blueprint $table) {
            $table->foreignId('character_item_id')->nullable()->constrained('character_items')->nullOnDelete();
            $table->unique(['character_item_id', 'slot_number'], 'player_relic_ordinary_socket_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('player_relics')->whereNotNull('character_item_id')->exists()) {
            throw new RuntimeException('通常装備に装着中の遺物があります。取り外してから戻してください。');
        }
        Schema::table('player_relics', function (Blueprint $table) {
            $table->dropUnique('player_relic_ordinary_socket_unique');
            $table->dropConstrainedForeignId('character_item_id');
        });
    }
};
