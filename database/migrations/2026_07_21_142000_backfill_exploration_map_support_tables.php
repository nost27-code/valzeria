<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('map_publication_logs')) {
            Schema::create('map_publication_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('map_id')->unique()->constrained('exploration_maps');
                $table->unsignedBigInteger('public_log_id')->nullable()->unique();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('monster_prefixes')) {
            DB::table('monster_prefixes')->insertOrIgnore(array_map(fn (array $prefix) => $prefix + ['eligible_biome_tags_json' => null, 'eligible_monster_families_json' => null, 'minimum_map_grade' => 'normal', 'normal_eligible' => true, 'boss_eligible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], [
                ['code' => 'ash_covered', 'display_name' => '灰被りの', 'stat_modifiers_json' => json_encode(['defense_percent' => 5]), 'reward_modifiers_json' => json_encode(['fire_material_percent' => 10]), 'weight' => 100],
                ['code' => 'black_iron', 'display_name' => '黒鉄の', 'stat_modifiers_json' => json_encode(['defense_percent' => 10]), 'reward_modifiers_json' => json_encode(['ore_percent' => 10]), 'weight' => 100],
                ['code' => 'ferocious', 'display_name' => '凶暴なる', 'stat_modifiers_json' => json_encode(['attack_percent' => 10]), 'reward_modifiers_json' => json_encode(['experience_percent' => 10]), 'weight' => 90],
                ['code' => 'swift', 'display_name' => '俊足の', 'stat_modifiers_json' => json_encode(['speed_percent' => 10]), 'reward_modifiers_json' => json_encode([]), 'weight' => 80],
                ['code' => 'treasure_bearing', 'display_name' => '宝を抱く', 'stat_modifiers_json' => json_encode([]), 'reward_modifiers_json' => json_encode(['gold_percent' => 10]), 'weight' => 70],
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('map_publication_logs');
    }
};
