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
        Schema::create('job_exp_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('job_level');
            $table->unsignedInteger('required_exp');
            $table->timestamps();

            $table->unique('job_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_exp_tables');
    }
};
