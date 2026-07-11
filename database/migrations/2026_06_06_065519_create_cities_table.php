<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $now = now();
        DB::table('cities')->insert([
            ['id' => 1, 'name' => '王都アークレア', 'sort_order' => 10, 'is_initial' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => '港町マリネス', 'sort_order' => 20, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => '精霊の森エルフィア', 'sort_order' => 30, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => '鍛冶街グランベルグ', 'sort_order' => 40, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => '雪原の町フロストリア', 'sort_order' => 50, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => '砂漠の宿場サンドラ', 'sort_order' => 60, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'name' => '魔導学院ルミナス', 'sort_order' => 70, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8, 'name' => '死霊街ネクロム', 'sort_order' => 80, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'name' => '天空神殿セレスティア', 'sort_order' => 90, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 10, 'name' => '魔王城ヴァルゼリア', 'sort_order' => 100, 'is_initial' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

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
