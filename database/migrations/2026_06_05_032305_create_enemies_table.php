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
        Schema::create('enemies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('max_hp')->default(10);
            $table->unsignedInteger('str')->default(5);
            $table->unsignedInteger('def')->default(5);
            $table->unsignedInteger('agi')->default(5);
            $table->unsignedInteger('mag')->default(5);
            $table->unsignedInteger('luk')->default(5);
            $table->unsignedInteger('exp_reward')->default(1);
            $table->unsignedInteger('gold_reward')->default(1);
            $table->unsignedInteger('appearance_weight')->default(10);
            $table->boolean('is_boss')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enemies');
    }
};
