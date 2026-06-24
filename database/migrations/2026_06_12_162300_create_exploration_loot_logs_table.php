<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exploration_loot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('character_item_id')->nullable()->constrained('character_items')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->boolean('penalized')->default(false);
            $table->timestamps();

            $table->index(['character_id', 'area_id', 'penalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exploration_loot_logs');
    }
};
