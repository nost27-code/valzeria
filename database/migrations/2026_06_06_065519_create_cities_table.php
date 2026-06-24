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
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('recommended_level_min')->default(1);
            $table->integer('recommended_level_max')->default(5);
            $table->integer('sort_order')->default(0);
            $table->string('unlock_condition_type')->nullable(); // 'area_cleared' 等
            $table->string('unlock_condition_value')->nullable(); // 解放条件となるエリアID等
            $table->boolean('is_initial')->default(false);
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'current_city_id')) {
                $table->unsignedBigInteger('current_city_id')->nullable()->after('current_job_id');
            }
            if (!Schema::hasColumn('characters', 'highest_city_id')) {
                $table->unsignedBigInteger('highest_city_id')->nullable()->after('current_city_id');
            }
        });

        Schema::table('areas', function (Blueprint $table) {
            if (!Schema::hasColumn('areas', 'city_id')) {
                $table->unsignedBigInteger('city_id')->nullable()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            if (Schema::hasColumn('areas', 'city_id')) {
                $table->dropColumn('city_id');
            }
        });

        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'current_city_id')) {
                $table->dropColumn(['current_city_id', 'highest_city_id']);
            }
        });

        Schema::dropIfExists('cities');
    }
};
