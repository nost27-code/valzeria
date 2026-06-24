<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_depth_gate_discoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('depth_key', 32);
            $table->string('depth_label', 32);
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_recorded_at')->nullable();
            $table->unsignedInteger('times_recorded')->default(1);
            $table->timestamps();

            $table->unique(['character_id', 'area_id', 'depth_key'], 'character_depth_gate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_depth_gate_discoveries');
    }
};
