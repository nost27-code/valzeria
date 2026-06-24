<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_master_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_classes')->cascadeOnDelete();
            $table->enum('bonus_type', [
                'hp_rate',
                'mp_rate',
                'atk_rate',
                'def_rate',
                'mag_rate',
                'spr_rate',
                'spd_rate',
                'luck_rate',
                'gold_rate',
                'drop_rate',
                'critical_rate',
                'evasion_rate',
                'heal_rate',
                'item_effect_rate'
            ]);
            $table->integer('bonus_value')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_master_bonuses');
    }
};
