<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_exploration_item_carries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('town_map_registrations')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('carried_count')->default(0);
            $table->unsignedSmallInteger('used_count')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'registration_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_exploration_item_carries');
    }
};
