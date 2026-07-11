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
        Schema::create('job_classes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('rank');
            $table->string('category')->nullable();
            $table->text('description')->nullable();

            $table->unsignedTinyInteger('max_job_level')->default(10);

            $table->unsignedSmallInteger('hp_rate')->default(100);
            $table->unsignedSmallInteger('mp_rate')->default(100);
            $table->unsignedSmallInteger('atk_rate')->default(100);
            $table->unsignedSmallInteger('def_rate')->default(100);
            $table->unsignedSmallInteger('mag_rate')->default(100);
            $table->unsignedSmallInteger('spr_rate')->default(100);
            $table->unsignedSmallInteger('spd_rate')->default(100);
            $table->unsignedSmallInteger('luck_rate')->default(100);

            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_classes');
    }
};
