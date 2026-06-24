<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('battle_logs', function (Blueprint $table) {
            $table->foreignId('dropped_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('dropped_character_item_id')->nullable()->constrained('character_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('battle_logs', function (Blueprint $table) {
            $table->dropForeign(['dropped_item_id']);
            $table->dropForeign(['dropped_character_item_id']);
            $table->dropColumn('dropped_item_id');
            $table->dropColumn('dropped_character_item_id');
        });
    }
};
