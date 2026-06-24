<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_equipment_daily_supplies')) {
            Schema::create('character_equipment_daily_supplies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->cascadeOnDelete();
                $table->date('claimed_on');
                $table->timestamps();

                $table->unique(['character_id', 'item_id', 'claimed_on'], 'character_equipment_daily_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_equipment_daily_supplies');
    }
};
