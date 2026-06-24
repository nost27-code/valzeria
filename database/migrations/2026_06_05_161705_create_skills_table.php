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
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->default('physical'); // physical, magical, healing, buff, debuff
            $table->integer('power')->default(0);
            $table->integer('mp_cost')->default(0);
            $table->integer('accuracy')->default(100);
            $table->integer('priority')->default(50);
            $table->string('attribute')->default('none');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
