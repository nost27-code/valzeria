<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('six_hero_seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('season_key', 7)->unique();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('six_hero_rankings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('six_hero_seasons')
                ->cascadeOnDelete();
            $table->string('room_key', 32);
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('rank');
            $table->unsignedInteger('official_attack_wins')->default(0);
            $table->unsignedInteger('official_attack_losses')->default(0);
            $table->unsignedInteger('defense_wins')->default(0);
            $table->unsignedInteger('defense_losses')->default(0);
            $table->timestamp('registered_at');
            $table->timestamp('first_place_since')->nullable();
            $table->timestamps();

            $table->unique(
                ['season_id', 'room_key', 'character_id'],
                'six_hero_rankings_season_room_character_uq',
            );
            $table->unique(
                ['season_id', 'room_key', 'rank'],
                'six_hero_rankings_season_room_rank_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('six_hero_rankings');
        Schema::dropIfExists('six_hero_seasons');
    }
};
