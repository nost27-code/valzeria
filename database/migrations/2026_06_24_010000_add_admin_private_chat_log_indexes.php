<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('public_logs')) {
            return;
        }

        Schema::table('public_logs', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'public_logs_type_created_at_idx');
            $table->index(['type', 'character_id', 'created_at'], 'public_logs_type_character_created_idx');
            $table->index(['type', 'receiver_id', 'created_at'], 'public_logs_type_receiver_created_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('public_logs')) {
            return;
        }

        Schema::table('public_logs', function (Blueprint $table) {
            $table->dropIndex('public_logs_type_created_at_idx');
            $table->dropIndex('public_logs_type_character_created_idx');
            $table->dropIndex('public_logs_type_receiver_created_idx');
        });
    }
};
