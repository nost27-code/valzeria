<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weapon_trait_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('operation', 32);
            $table->unsignedBigInteger('base_character_item_id');
            $table->unsignedBigInteger('material_character_item_id');
            $table->json('before_snapshot');
            $table->json('material_snapshot');
            $table->json('after_snapshot');
            $table->unsignedBigInteger('gold_cost');
            $table->timestamps();

            $table->index(['character_id', 'created_at'], 'weapon_trait_logs_character_created_idx');
            $table->index(['operation', 'created_at'], 'weapon_trait_logs_operation_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weapon_trait_operation_logs');
    }
};
