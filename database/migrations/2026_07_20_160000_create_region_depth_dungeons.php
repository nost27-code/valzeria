<?php

use App\Models\Area;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Material;
use App\Models\MaterialDrop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_region_dungeon_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('dungeon_key', 100);
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->timestamp('entered_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 50)->nullable();
            $table->unsignedBigInteger('total_exp')->default(0);
            $table->unsignedBigInteger('total_job_exp')->default(0);
            $table->unsignedBigInteger('max_danger_rate')->default(0);
            $table->unsignedBigInteger('max_chain_count')->default(0);
            $table->boolean('new_danger_record')->default(false);
            $table->timestamp('public_log_sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['character_id', 'status']);
        });

        Schema::create('character_region_dungeon_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('dungeon_key', 100);
            $table->unsignedBigInteger('best_danger_rate')->default(0);
            $table->unsignedBigInteger('best_chain_count')->default(0);
            $table->unsignedBigInteger('best_total_exp')->default(0);
            $table->timestamp('best_danger_at')->nullable();
            $table->timestamp('best_chain_at')->nullable();
            $table->timestamp('best_total_exp_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'dungeon_key']);
        });

        $area = Area::updateOrCreate(
            ['slug' => 'granberg_black_furnace'],
            [
                'name' => '黒炉深坑',
                'description' => 'グランベルグの地下深くへ続く、底の見えない旧採掘坑。帰還するまで危険度は蓄積される。',
                'recommended_level_min' => 55,
                'recommended_level_max' => 57,
                'unlock_order' => 9,
                'unlock_required_area_id' => null,
                'sort_order' => 490,
                'city_id' => 4,
            ]
        );

        // 数値を新規に設計せず、グランベルグ最終探索地の既存敵を基礎値として再利用する。
        $sourceEnemies = Enemy::where('area_id', 28)->where('is_boss', false)->orderBy('sort_order')->take(5)->get();
        $names = ['黒鉄坑のスライム', '坑道ゴーレム', '炉熱のコウモリ', '黒鉱甲虫', '旧坑道の採掘兵'];
        foreach ($sourceEnemies->values() as $index => $source) {
            $enemy = Enemy::updateOrCreate(
                ['area_id' => $area->id, 'name' => $names[$index] ?? ('黒炉の魔物' . ($index + 1))],
                array_merge($source->only(['level', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk', 'exp_reward', 'gold_reward', 'job_exp_reward', 'appearance_weight', 'family_key', 'species_key', 'role', 'is_stat_locked']), [
                    'is_boss' => false,
                    'sort_order' => $index + 1,
                ])
            );

            foreach (MaterialDrop::where('enemy_id', $source->id)->where('is_active', true)->get() as $drop) {
                MaterialDrop::updateOrCreate(
                    ['enemy_id' => $enemy->id, 'material_id' => $drop->material_id],
                    $drop->only(['drop_rate', 'drop_first_clear_only', 'drop_timing', 'is_active'])
                );
            }

            foreach (EnemyDrop::where('enemy_id', $source->id)->where('is_active', true)->get() as $drop) {
                EnemyDrop::updateOrCreate(
                    ['enemy_id' => $enemy->id, 'item_id' => $drop->item_id],
                    $drop->only(['drop_rate', 'min_character_level', 'max_character_level', 'is_active'])
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_region_dungeon_records');
        Schema::dropIfExists('character_region_dungeon_runs');
        Area::where('slug', 'granberg_black_furnace')->delete();
    }
};
