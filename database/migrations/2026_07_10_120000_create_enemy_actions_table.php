<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enemy_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enemy_id')->constrained()->cascadeOnDelete();
            $table->string('action_key', 50);
            $table->string('name', 100);
            $table->string('action_type', 50);
            $table->unsignedSmallInteger('selection_weight')->default(100);
            $table->unsignedSmallInteger('power_percent')->default(100);
            $table->unsignedTinyInteger('hit_count')->default(1);
            $table->unsignedTinyInteger('effect_percent')->default(0);
            $table->unsignedTinyInteger('duration_turns')->default(0);
            $table->unsignedTinyInteger('cooldown_turns')->default(0);
            $table->unsignedTinyInteger('max_uses_per_battle')->nullable();
            $table->unsignedTinyInteger('trigger_turn')->nullable();
            $table->string('trigger_key', 50)->nullable();
            $table->unsignedTinyInteger('trigger_value')->nullable();
            $table->boolean('can_use_on_first_turn')->default(true);
            $table->boolean('is_telegraphed')->default(false);
            $table->unsignedTinyInteger('telegraph_turns')->default(0);
            $table->boolean('can_be_guarded')->default(false);
            $table->decimal('guard_reduction_rate', 4, 2)->default(0);
            $table->boolean('cancel_on_enemy_death')->default(true);
            $table->boolean('guarantee_first_use')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['enemy_id', 'action_key']);
            $table->index(['enemy_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enemy_actions');
    }
};
