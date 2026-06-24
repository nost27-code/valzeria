<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_item_daily_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->date('claimed_on');
            $table->unsignedSmallInteger('supplied_count')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'item_id', 'claimed_on'], 'daily_supply_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_item_daily_supplies');
    }
};
