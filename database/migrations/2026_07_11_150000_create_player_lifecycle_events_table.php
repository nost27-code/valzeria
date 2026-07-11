<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name', 64);
            $table->string('event_key', 100);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['user_id', 'event_key'], 'player_lifecycle_events_user_key_unique');
            $table->index(['event_name', 'occurred_at', 'user_id'], 'player_lifecycle_events_name_time_user_idx');
            $table->index(['character_id', 'occurred_at'], 'player_lifecycle_events_character_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_lifecycle_events');
    }
};
