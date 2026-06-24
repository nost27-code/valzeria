<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('equipment_decomposition_logs')) {
            Schema::create('equipment_decomposition_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('equipment_instance_id');
                $table->foreignId('equipment_master_id')->constrained('items')->cascadeOnDelete();
                $table->string('equipment_name');
                $table->string('rank')->nullable();
                $table->unsignedSmallInteger('enhancement_level')->default(0);
                $table->json('obtained_materials');
                $table->timestamps();

                $table->index(['character_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_decomposition_logs');
    }
};
