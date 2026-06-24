<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('champ_states')) {
            Schema::create('champ_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->string('player_name', 50);
                $table->string('job_name', 50)->nullable();
                $table->unsignedInteger('job_rank')->default(1);
                $table->unsignedInteger('level')->default(1);
                $table->bigInteger('current_hp');
                $table->bigInteger('max_hp');
                $table->integer('atk')->default(0);
                $table->integer('def')->default(0);
                $table->integer('mag')->default(0);
                $table->integer('spr')->default(0);
                $table->integer('spd')->default(0);
                $table->integer('luk')->default(0);
                $table->unsignedInteger('defense_count')->default(0);
                $table->timestamp('appointed_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('champ_battle_logs')) {
            Schema::create('champ_battle_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('champ_character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->string('champ_player_name', 50);
                $table->foreignId('challenger_character_id')->constrained('characters')->cascadeOnDelete();
                $table->string('challenger_player_name', 50);
                $table->bigInteger('damage')->default(0);
                $table->boolean('is_champ_defeated')->default(false);
                $table->bigInteger('champ_hp_before')->default(0);
                $table->bigInteger('champ_hp_after')->default(0);
                $table->bigInteger('exp_gained')->default(0);
                $table->unsignedInteger('job_exp_gained')->default(0);
                $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
                $table->string('material_name', 100)->nullable();
                $table->unsignedInteger('material_quantity')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('champ_histories')) {
            Schema::create('champ_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->string('player_name', 50);
                $table->string('job_name', 50)->nullable();
                $table->unsignedInteger('job_rank')->default(1);
                $table->unsignedInteger('level')->default(1);
                $table->bigInteger('max_hp');
                $table->unsignedInteger('defense_count')->default(0);
                $table->timestamp('appointed_at');
                $table->timestamp('defeated_at')->nullable();
                $table->foreignId('defeated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->string('defeated_by_player_name', 50)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('characters') && !Schema::hasColumn('characters', 'last_champ_battle_at')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->timestamp('last_champ_battle_at')->nullable()->after('last_battle_at');
            });
        }

        $this->seedMaterial();
        $this->seedInitialChamp();
    }

    public function down(): void
    {
        if (Schema::hasTable('characters') && Schema::hasColumn('characters', 'last_champ_battle_at')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->dropColumn('last_champ_battle_at');
            });
        }

        Schema::dropIfExists('champ_histories');
        Schema::dropIfExists('champ_battle_logs');
        Schema::dropIfExists('champ_states');
    }

    private function seedMaterial(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        $values = [
            'name' => '挑戦者の証片',
            'category' => 'チャンプ戦素材',
            'rarity' => 'N',
            'element' => null,
            'main_use' => 'チャンプ戦記念素材',
            'npc_sale_price' => 0,
            'is_tradable' => false,
            'city_id' => null,
            'dungeon_id' => null,
            'source_enemy_id' => null,
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        foreach ([
            'material_type' => 'champ',
            'category_id' => null,
            'rank_tier' => 1,
            'is_consumable' => false,
            'obtain_method' => 'チャンプ戦への挑戦で入手',
        ] as $column => $value) {
            if (Schema::hasColumn('materials', $column)) {
                $values[$column] = $value;
            }
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => 'MAT_CHAMP_CHALLENGER_FRAGMENT'],
            $values
        );
    }

    private function seedInitialChamp(): void
    {
        if (!Schema::hasTable('champ_states') || DB::table('champ_states')->exists()) {
            return;
        }

        $now = now();
        DB::table('champ_states')->insert([
            'character_id' => null,
            'player_name' => '冒険者協会の試練官',
            'job_name' => '戦士',
            'job_rank' => 1,
            'level' => 30,
            'current_hp' => 520,
            'max_hp' => 520,
            'atk' => 95,
            'def' => 58,
            'mag' => 24,
            'spr' => 38,
            'spd' => 52,
            'luk' => 20,
            'defense_count' => 0,
            'appointed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
