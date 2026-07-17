<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battle_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'character_id'], 'battle_logs_created_character_idx');
        });
        Schema::table('gold_transactions', function (Blueprint $table): void {
            $table->index(['created_at', 'character_id'], 'gold_transactions_created_character_idx');
        });
        Schema::table('kiseki_transactions', function (Blueprint $table): void {
            $table->index(['created_at', 'character_id'], 'kiseki_transactions_created_character_idx');
        });
        Schema::table('security_login_observations', function (Blueprint $table): void {
            $table->index(['last_observed_at', 'ip_hash'], 'security_login_observed_ip_idx');
        });
    }

    public function down(): void
    {
        Schema::table('security_login_observations', function (Blueprint $table): void {
            $table->dropIndex('security_login_observed_ip_idx');
        });
        Schema::table('kiseki_transactions', function (Blueprint $table): void {
            $table->dropIndex('kiseki_transactions_created_character_idx');
        });
        Schema::table('gold_transactions', function (Blueprint $table): void {
            $table->dropIndex('gold_transactions_created_character_idx');
        });
        Schema::table('battle_logs', function (Blueprint $table): void {
            $table->dropIndex('battle_logs_created_character_idx');
        });
    }
};
