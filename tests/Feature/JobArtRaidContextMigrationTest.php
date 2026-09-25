<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobArtRaidContextMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('character_job_art_context_settings');
        Schema::dropIfExists('character_job_art_slots');

        Schema::create('character_job_art_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->unsignedTinyInteger('slot_no');
            $table->unsignedBigInteger('skill_id');
            $table->string('activation_policy', 20)->default('normal');
            $table->string('condition_key', 40)->default('always');
            $table->timestamps();
            $table->unique(['character_id', 'battle_context', 'slot_no']);
            $table->unique(['character_id', 'battle_context', 'skill_id']);
        });
        Schema::create('character_job_art_context_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->string('sp_policy', 20)->default('aggressive');
            $table->string('strategy_mode', 20)->default('custom');
            $table->json('strategy_settings')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'battle_context']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('character_job_art_context_settings');
        Schema::dropIfExists('character_job_art_slots');

        parent::tearDown();
    }

    public function test_migration_copies_complete_boss_context_once_without_overwriting_existing_raid_data(): void
    {
        $now = now();
        DB::table('character_job_art_slots')->insert([
            ['character_id' => 1, 'battle_context' => 'boss', 'slot_no' => 1, 'skill_id' => 101,
                'activation_policy' => 'conserve', 'condition_key' => 'self_hp_le_50', 'created_at' => $now, 'updated_at' => $now],
            ['character_id' => 1, 'battle_context' => 'boss', 'slot_no' => 2, 'skill_id' => 105,
                'activation_policy' => 'aggressive', 'condition_key' => 'always', 'created_at' => $now, 'updated_at' => $now],
            ['character_id' => 2, 'battle_context' => 'boss', 'slot_no' => 1, 'skill_id' => 201,
                'activation_policy' => 'normal', 'condition_key' => 'always', 'created_at' => $now, 'updated_at' => $now],
            ['character_id' => 2, 'battle_context' => 'raid', 'slot_no' => 1, 'skill_id' => 299,
                'activation_policy' => 'aggressive', 'condition_key' => 'target_hp_le_30', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('character_job_art_context_settings')->insert([
            ['character_id' => 1, 'battle_context' => 'boss', 'sp_policy' => 'conserve', 'strategy_mode' => 'custom',
                'strategy_settings' => json_encode(['base_priority' => 'combo', 'sp_output' => 'max']), 'created_at' => $now, 'updated_at' => $now],
            ['character_id' => 2, 'battle_context' => 'boss', 'sp_policy' => 'normal', 'strategy_mode' => 'custom',
                'strategy_settings' => json_encode(['base_priority' => 'ultimate', 'sp_output' => 'high']), 'created_at' => $now, 'updated_at' => $now],
            ['character_id' => 2, 'battle_context' => 'raid', 'sp_policy' => 'aggressive', 'strategy_mode' => 'auto',
                'strategy_settings' => json_encode(['base_priority' => 'balanced', 'sp_output' => 'low']), 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration = require database_path('migrations/2026_09_25_120000_copy_boss_job_art_context_to_raid.php');
        $migration->up();
        $migration->up();

        $copiedSlots = DB::table('character_job_art_slots')
            ->where('character_id', 1)->where('battle_context', 'raid')->orderBy('slot_no')->get();
        $this->assertSame([101, 105], $copiedSlots->pluck('skill_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(['conserve', 'aggressive'], $copiedSlots->pluck('activation_policy')->all());
        $this->assertSame(['self_hp_le_50', 'always'], $copiedSlots->pluck('condition_key')->all());

        $copiedSetting = DB::table('character_job_art_context_settings')
            ->where('character_id', 1)->where('battle_context', 'raid')->first();
        $this->assertSame('conserve', $copiedSetting->sp_policy);
        $this->assertSame('custom', $copiedSetting->strategy_mode);
        $this->assertSame(
            ['base_priority' => 'combo', 'sp_output' => 'max'],
            json_decode((string) $copiedSetting->strategy_settings, true),
        );

        $this->assertSame(299, (int) DB::table('character_job_art_slots')
            ->where('character_id', 2)->where('battle_context', 'raid')->sole()->skill_id);
        $this->assertSame('low', json_decode((string) DB::table('character_job_art_context_settings')
            ->where('character_id', 2)->where('battle_context', 'raid')->sole()->strategy_settings, true)['sp_output']);

        DB::table('character_job_art_context_settings')
            ->where('character_id', 1)->where('battle_context', 'raid')
            ->update(['sp_policy' => 'aggressive']);
        $migration->down();
        $this->assertSame('aggressive', DB::table('character_job_art_context_settings')
            ->where('character_id', 1)->where('battle_context', 'raid')->value('sp_policy'));
    }
}
