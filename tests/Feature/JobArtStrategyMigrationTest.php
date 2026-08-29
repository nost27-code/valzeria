<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobArtStrategyMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('character_job_art_context_settings');

        parent::tearDown();
    }

    public function test_migration_preserves_existing_contexts_and_defaults_new_contexts_to_custom(): void
    {
        Schema::dropIfExists('character_job_art_context_settings');
        Schema::create('character_job_art_context_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->string('sp_policy', 20)->default('aggressive');
            $table->timestamps();
            $table->unique(['character_id', 'battle_context']);
        });
        DB::table('character_job_art_context_settings')->insert([
            'character_id' => 1,
            'battle_context' => 'normal',
            'sp_policy' => 'conserve',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_20_140000_add_strategy_to_character_job_art_context_settings.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('character_job_art_context_settings', 'strategy_mode'));
        $this->assertTrue(Schema::hasColumn('character_job_art_context_settings', 'strategy_settings'));
        $this->assertSame('custom', DB::table('character_job_art_context_settings')->where('character_id', 1)->value('strategy_mode'));
        $this->assertSame('conserve', DB::table('character_job_art_context_settings')->where('character_id', 1)->value('sp_policy'));

        DB::table('character_job_art_context_settings')->insert([
            'character_id' => 2,
            'battle_context' => 'normal',
            'sp_policy' => 'aggressive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame('custom', DB::table('character_job_art_context_settings')->where('character_id', 2)->value('strategy_mode'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('character_job_art_context_settings', 'strategy_mode'));
        $this->assertFalse(Schema::hasColumn('character_job_art_context_settings', 'strategy_settings'));
        $this->assertTrue(Schema::hasColumn('character_job_art_context_settings', 'sp_policy'));
    }

    public function test_migration_recovers_an_interrupted_auto_default_before_adding_settings(): void
    {
        Schema::dropIfExists('character_job_art_context_settings');
        Schema::create('character_job_art_context_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->string('sp_policy', 20)->default('aggressive');
            $table->string('strategy_mode', 20)->default('auto');
            $table->timestamps();
            $table->unique(['character_id', 'battle_context']);
        });
        DB::table('character_job_art_context_settings')->insert([
            'character_id' => 1,
            'battle_context' => 'normal',
            'sp_policy' => 'aggressive',
            'strategy_mode' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_20_140000_add_strategy_to_character_job_art_context_settings.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('character_job_art_context_settings', 'strategy_settings'));
        $this->assertSame('custom', DB::table('character_job_art_context_settings')->where('character_id', 1)->value('strategy_mode'));
    }
}
