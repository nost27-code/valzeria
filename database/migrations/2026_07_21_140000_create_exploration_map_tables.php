<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enemies', function (Blueprint $table): void {
            $table->json('map_biome_tags')->nullable()->after('appearance_weight');
            $table->unsignedSmallInteger('map_min_level')->nullable()->after('map_biome_tags');
            $table->unsignedSmallInteger('map_max_level')->nullable()->after('map_min_level');
            $table->boolean('map_normal_eligible')->default(true)->after('map_max_level');
            $table->boolean('map_boss_eligible')->default(false)->after('map_normal_eligible');
            $table->unsignedInteger('map_base_weight')->default(100)->after('map_boss_eligible');
        });

        Schema::create('monster_prefixes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('display_name');
            $table->json('eligible_biome_tags_json')->nullable();
            $table->json('eligible_monster_families_json')->nullable();
            $table->string('minimum_map_grade')->default('normal');
            $table->boolean('normal_eligible')->default(true);
            $table->boolean('boss_eligible')->default(false);
            $table->json('stat_modifiers_json')->nullable();
            $table->json('reward_modifiers_json')->nullable();
            $table->unsignedInteger('weight')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('monster_prefixes')->insert(array_map(fn (array $prefix) => $prefix + ['eligible_biome_tags_json' => null, 'eligible_monster_families_json' => null, 'minimum_map_grade' => 'normal', 'normal_eligible' => true, 'boss_eligible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], [
            ['code' => 'ash_covered', 'display_name' => '灰被りの', 'stat_modifiers_json' => json_encode(['defense_percent' => 5]), 'reward_modifiers_json' => json_encode(['fire_material_percent' => 10]), 'weight' => 100],
            ['code' => 'black_iron', 'display_name' => '黒鉄の', 'stat_modifiers_json' => json_encode(['defense_percent' => 10]), 'reward_modifiers_json' => json_encode(['ore_percent' => 10]), 'weight' => 100],
            ['code' => 'ferocious', 'display_name' => '凶暴なる', 'stat_modifiers_json' => json_encode(['attack_percent' => 10]), 'reward_modifiers_json' => json_encode(['experience_percent' => 10]), 'weight' => 90],
            ['code' => 'swift', 'display_name' => '俊足の', 'stat_modifiers_json' => json_encode(['speed_percent' => 10]), 'reward_modifiers_json' => json_encode([]), 'weight' => 80],
            ['code' => 'treasure_bearing', 'display_name' => '宝を抱く', 'stat_modifiers_json' => json_encode([]), 'reward_modifiers_json' => json_encode(['gold_percent' => 10]), 'weight' => 70],
        ]));

        Schema::create('exploration_maps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_character_id')->constrained('characters');
            $table->foreignId('source_area_id')->constrained('areas');
            $table->foreignId('source_monster_id')->nullable()->constrained('enemies');
            $table->uuid('source_drop_event_uuid')->unique();
            $table->unsignedTinyInteger('seed_version')->default(1);
            $table->text('seed_encrypted');
            $table->string('seed_hash', 64)->index();
            $table->unsignedTinyInteger('generation_version')->default(1);
            $table->string('map_grade');
            $table->unsignedSmallInteger('map_level');
            $table->string('dungeon_type');
            $table->string('reward_profile');
            $table->unsignedSmallInteger('exploration_limit');
            $table->string('name');
            $table->json('name_parts_json');
            $table->json('normal_monster_variants_json');
            $table->json('boss_monster_variants_json')->nullable();
            $table->json('environment_effects_json')->nullable();
            $table->json('reward_modifiers_json')->nullable();
            $table->json('generation_payload_json')->nullable();
            $table->foreignId('recommended_town_id')->nullable()->constrained('cities');
            $table->string('status')->default('uninvestigated')->index();
            $table->timestamps();
        });

        Schema::create('town_map_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->unique()->constrained('exploration_maps');
            $table->foreignId('town_id')->constrained('cities');
            $table->string('survey_status')->default('not_started')->index();
            $table->unsignedInteger('survey_cost')->default(0);
            $table->timestamp('survey_started_at')->nullable();
            $table->timestamp('survey_completed_at')->nullable();
            $table->unsignedInteger('entry_fee_per_exploration')->default(0);
            $table->timestamp('entry_fee_changed_at')->nullable();
            $table->unsignedSmallInteger('exploration_limit');
            $table->unsignedSmallInteger('remaining_explorations');
            $table->unsignedSmallInteger('consumed_explorations')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('status')->default('surveying')->index();
            $table->timestamps();
        });

        Schema::create('map_exploration_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('registration_id')->constrained('town_map_registrations');
            $table->foreignId('map_id')->constrained('exploration_maps');
            $table->foreignId('character_id')->constrained('characters');
            $table->unsignedTinyInteger('requested_count');
            $table->unsignedTinyInteger('reserved_count');
            $table->unsignedTinyInteger('executed_count')->default(0);
            $table->unsignedSmallInteger('first_exploration_index');
            $table->unsignedSmallInteger('last_exploration_index');
            $table->unsignedInteger('fee_per_exploration')->default(0);
            $table->unsignedInteger('total_fee')->default(0);
            $table->json('result_summary_json')->nullable();
            $table->string('status')->default('reserved')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('result_viewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('map_exploration_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('map_exploration_batches');
            $table->foreignId('map_id')->constrained('exploration_maps');
            $table->foreignId('registration_id')->constrained('town_map_registrations');
            $table->foreignId('character_id')->constrained('characters');
            $table->unsignedSmallInteger('global_exploration_index');
            $table->string('encounter_seed_hash', 64);
            $table->string('reward_seed_hash', 64);
            $table->json('monster_variants_json');
            $table->string('battle_result');
            $table->unsignedInteger('experience')->default(0);
            $table->unsignedInteger('gold')->default(0);
            $table->json('drops_json')->nullable();
            $table->timestamps();
            $table->unique(['map_id', 'global_exploration_index']);
        });

        Schema::create('map_income_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->unique()->constrained('map_exploration_batches');
            $table->foreignId('map_id')->constrained('exploration_maps');
            $table->foreignId('registration_id')->constrained('town_map_registrations');
            $table->foreignId('payer_character_id')->constrained('characters');
            $table->foreignId('owner_character_id')->constrained('characters');
            $table->unsignedTinyInteger('executed_count');
            $table->unsignedInteger('total_entry_fee');
            $table->unsignedInteger('owner_share')->default(0);
            $table->unsignedInteger('town_share')->default(0);
            $table->unsignedInteger('system_share')->default(0);
            $table->timestamps();
        });
        Schema::create('map_publication_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->unique()->constrained('exploration_maps');
            $table->unsignedBigInteger('public_log_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_publication_logs');
        Schema::dropIfExists('map_income_logs');
        Schema::dropIfExists('map_exploration_results');
        Schema::dropIfExists('map_exploration_batches');
        Schema::dropIfExists('town_map_registrations');
        Schema::dropIfExists('exploration_maps');
        Schema::dropIfExists('monster_prefixes');
        Schema::table('enemies', function (Blueprint $table): void {
            $table->dropColumn(['map_biome_tags', 'map_min_level', 'map_max_level', 'map_normal_eligible', 'map_boss_eligible', 'map_base_weight']);
        });
    }
};
