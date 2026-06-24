<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_sub_area_exploration_states')) {
            return;
        }

        Schema::create('character_sub_area_exploration_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->unique();
            $table->unsignedBigInteger('sub_area_id')->nullable();
            $table->unsignedBigInteger('sub_area_route_id')->nullable();
            $table->unsignedInteger('exploration_point')->default(0);
            $table->unsignedInteger('chain_count')->default(0);
            $table->unsignedInteger('danger_rate')->default(0);
            $table->boolean('sub_area_lord_encountered')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->index(['sub_area_id', 'sub_area_route_id'], 'sub_area_state_area_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_sub_area_exploration_states');
    }
};
