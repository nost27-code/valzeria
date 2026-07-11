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
        if (!Schema::hasTable('titles')) {
            Schema::create('titles', function (Blueprint $table) {
                $table->id();
                $table->string('category');
                $table->string('rarity');
                $table->string('name');
                $table->text('description');
                $table->text('hint');
                $table->string('unlock_type');
                $table->string('target_type')->nullable();
                $table->string('target_id')->nullable();
                $table->string('source_master')->nullable();
                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('is_hidden')->default(false);
                $table->timestamps();
            });
        }

        Schema::create('character_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->foreignId('title_id')->constrained()->onDelete('cascade');
            $table->boolean('is_equipped')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_titles');
    }
};
