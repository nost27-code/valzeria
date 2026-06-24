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
        if (!Schema::hasTable('character_materials')) {
            Schema::create('character_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->unsignedBigInteger('material_id');
                $table->integer('quantity')->default(0);
                $table->timestamps();

                $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
                $table->foreign('material_id')->references('id')->on('materials')->onDelete('cascade');
                $table->unique(['character_id', 'material_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_materials');
    }
};
