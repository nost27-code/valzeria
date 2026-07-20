<?php

use App\Models\Area;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_depth_dungeons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('source_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('baseline_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('entry_gold')->default(0);
            $table->json('entry_materials')->nullable();
            $table->unsignedTinyInteger('danger_increase_percent')->default(0);
            $table->json('base_stat_multipliers');
            $table->decimal('base_exp_multiplier', 8, 4)->default(1);
            $table->unsignedTinyInteger('base_job_exp')->default(0);
            $table->decimal('main_stat_per_danger', 8, 5)->default(0);
            $table->decimal('hp_per_danger', 8, 5)->default(0);
            $table->decimal('agi_luk_per_danger', 8, 5)->default(0);
            $table->decimal('exp_per_danger', 8, 5)->default(0);
            $table->decimal('exp_multiplier_cap', 8, 4)->default(1);
            $table->unsignedTinyInteger('job_exp_cap')->default(3);
            $table->unsignedSmallInteger('danger_per_guaranteed_bonus')->default(200);
            $table->unsignedSmallInteger('remainder_percent_divisor')->default(2);
            $table->unsignedInteger('public_log_minimum_danger')->default(100);
            $table->json('ore_vein')->nullable();
            $table->timestamps();
            $table->index(['city_id', 'is_enabled']);
        });

        $area = Area::query()->where('slug', 'granberg_black_furnace')->first();
        if (!$area) {
            return;
        }

        DB::table('region_depth_dungeons')->updateOrInsert(
            ['key' => 'granberg_black_furnace'],
            [
                'name' => '黒炉深坑',
                'description' => '一歩踏み込むごとに、坑道の熱と魔物の気配が濃くなる。引き返すまで、この深みはお前を離さない。',
                'city_id' => 4,
                'area_id' => $area->id,
                'source_area_id' => $area->id,
                'baseline_area_id' => null,
                'is_enabled' => true,
                'entry_gold' => 10000,
                'entry_materials' => json_encode([
                    ['code' => 'WEV0026', 'quantity' => 2],
                    ['code' => '5031', 'quantity' => 2],
                    ['code' => 'WEV0039', 'quantity' => 1],
                    ['code' => '5032', 'quantity' => 1],
                ], JSON_UNESCAPED_UNICODE),
                'danger_increase_percent' => 33,
                'base_stat_multipliers' => json_encode(['hp' => 1.427, 'str' => 1.397, 'def' => 1.388, 'agi' => 1.484, 'mag' => 1.208, 'spr' => 1.287, 'luk' => 1.234]),
                'base_exp_multiplier' => 1.345,
                'base_job_exp' => 3,
                'main_stat_per_danger' => 0.01,
                'hp_per_danger' => 0.005,
                'agi_luk_per_danger' => 0.005,
                'exp_per_danger' => 0.0005,
                'exp_multiplier_cap' => 2.0,
                'job_exp_cap' => 8,
                'danger_per_guaranteed_bonus' => 200,
                'remainder_percent_divisor' => 2,
                'public_log_minimum_danger' => 100,
                'ore_vein' => json_encode(['chain_interval' => 10, 'high_grade_unlock_danger' => 200]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('region_depth_dungeons');
    }
};
