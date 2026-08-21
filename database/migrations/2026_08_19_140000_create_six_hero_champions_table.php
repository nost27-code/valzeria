<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('six_hero_champions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('six_hero_seasons')
                ->cascadeOnDelete();
            $table->string('room_key', 32);
            $table->foreignId('character_id')
                ->nullable()
                ->constrained('characters')
                ->nullOnDelete();
            $table->string('character_name_snapshot')->nullable();
            $table->boolean('is_vacant');
            $table->string('vacancy_reason', 64)->nullable();
            $table->unsignedInteger('registered_count');
            $table->unsignedInteger('official_battle_count');
            $table->unsignedInteger('official_attack_wins')->nullable();
            $table->unsignedInteger('official_attack_losses')->nullable();
            $table->unsignedInteger('defense_wins')->nullable();
            $table->unsignedInteger('defense_losses')->nullable();
            $table->timestamps();

            $table->unique(
                ['season_id', 'room_key'],
                'six_hero_champions_season_room_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('six_hero_champions');
    }
};
