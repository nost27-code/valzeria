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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // weapon, armor, accessory
            $table->text('description')->nullable();
            $table->string('rarity')->default('normal');
            $table->integer('price')->default(0);
            $table->integer('hp_bonus')->default(0);
            $table->integer('str_bonus')->default(0);
            $table->integer('def_bonus')->default(0);
            $table->integer('agi_bonus')->default(0);
            $table->integer('mag_bonus')->default(0);
            $table->integer('luk_bonus')->default(0);
            $table->integer('required_level')->default(1);
            $table->boolean('is_shop_item')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
