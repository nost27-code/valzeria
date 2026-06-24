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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_code')->unique();
            $table->string('name');
            $table->string('item_type');
            $table->string('result_item_name');
            $table->foreignId('result_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->integer('required_level')->default(1);
            $table->integer('area_id')->nullable();
            $table->string('area_name')->nullable();
            $table->string('city_name')->nullable();
            $table->string('element')->nullable();
            $table->integer('cost')->default(0);
            $table->integer('success_rate')->default(100);
            $table->string('unlock_condition_type')->nullable();
            $table->string('unlock_condition_value')->nullable();
            $table->text('unlock_condition_desc')->nullable();
            $table->json('materials')->nullable();
            $table->string('key_material_code')->nullable();
            $table->string('key_material_name')->nullable();
            $table->boolean('consume_key_material')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
