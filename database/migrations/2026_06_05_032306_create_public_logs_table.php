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
        Schema::create('public_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('system'); // system, battle, area, growth, chat
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('message');
            $table->unsignedInteger('importance')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_logs');
    }
};
