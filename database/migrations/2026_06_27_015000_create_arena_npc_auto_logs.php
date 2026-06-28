<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('arena_npc_auto_logs')) {
            return;
        }

        Schema::create('arena_npc_auto_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_npc_ranking_id')->constrained('arena_npc_rankings')->cascadeOnDelete();
            $table->unsignedBigInteger('attacker_npc_id');
            $table->string('defender_type', 16);
            $table->foreignId('defender_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('defender_npc_ranking_id')->nullable()->constrained('arena_npc_rankings')->nullOnDelete();
            $table->unsignedBigInteger('defender_npc_id')->nullable();
            $table->boolean('is_attacker_win');
            $table->integer('attacker_old_rank');
            $table->integer('attacker_new_rank');
            $table->integer('defender_old_rank');
            $table->integer('defender_new_rank');
            $table->timestamps();

            $table->index(['defender_character_id', 'created_at'], 'arena_npc_auto_logs_defender_character_idx');
            $table->index(['attacker_npc_ranking_id', 'created_at'], 'arena_npc_auto_logs_attacker_npc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_npc_auto_logs');
    }
};
