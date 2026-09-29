<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_field_positions')) {
            return;
        }

        Schema::create('character_field_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plane', 16)->default('land');
            // フィールド上の位置（px）。1マス = config('valzeria_field.tile') px
            $table->unsignedInteger('x');
            $table->unsignedInteger('y');
            $table->unsignedTinyInteger('facing')->default(0);
            $table->timestamp('moved_at')->nullable();
            $table->timestamps();

            $table->index(['plane', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_field_positions');
    }
};
