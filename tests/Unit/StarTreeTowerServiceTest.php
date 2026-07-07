<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\TowerCharacterRecord;
use App\Models\TowerRun;
use App\Models\TowerRunEvent;
use App\Models\TowerWeeklyRecord;
use App\Services\CharacterStatusService;
use App\Services\StarTreeTowerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StarTreeTowerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'star_tree_tower.star_tree.enabled' => true,
            'extra_content.contents.star_tree_tower.default_enabled' => true,
        ]);

        foreach ([
            'tower_run_events',
            'tower_character_records',
            'tower_weekly_records',
            'tower_runs',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('highest_city_id')->default(1);
            $table->unsignedInteger('equipment_storage_limit')->default(300);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tower_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->string('season_key', 20);
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('current_floor')->default(1);
            $table->unsignedInteger('reached_floor')->default(1);
            $table->unsignedInteger('cleared_floor')->default(0);
            $table->unsignedInteger('failed_floor')->nullable();
            $table->unsignedInteger('tower_max_hp')->default(0);
            $table->unsignedInteger('tower_current_hp')->default(0);
            $table->unsignedInteger('tower_max_mp')->default(0);
            $table->unsignedInteger('tower_current_mp')->default(0);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_losses')->default(0);
            $table->unsignedInteger('merchant_encounter_count')->default(0);
            $table->unsignedInteger('last_merchant_floor')->nullable();
            $table->string('pending_event', 50)->nullable();
            $table->unsignedInteger('gold_spent')->default(0);
            $table->unsignedInteger('stamina_spent')->default(0);
            $table->string('last_event_type', 50)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tower_run_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tower_run_id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedInteger('floor');
            $table->string('event_type', 50);
            $table->string('result', 50)->nullable();
            $table->string('enemy_name', 100)->nullable();
            $table->string('enemy_profile', 50)->nullable();
            $table->unsignedInteger('damage_taken')->default(0);
            $table->unsignedInteger('hp_after')->nullable();
            $table->unsignedInteger('mp_after')->nullable();
            $table->integer('gold_delta')->default(0);
            $table->unsignedInteger('stamina_delta')->default(0);
            $table->unsignedInteger('exp_gained')->default(0);
            $table->unsignedInteger('job_exp_gained')->default(0);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tower_weekly_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->string('season_key', 20);
            $table->unsignedInteger('best_cleared_floor')->default(0);
            $table->unsignedInteger('best_failed_floor')->nullable();
            $table->unsignedBigInteger('best_run_id')->nullable();
            $table->dateTime('achieved_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'tower_key', 'season_key']);
        });

        Schema::create('tower_character_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->unsignedInteger('best_cleared_floor')->default(0);
            $table->unsignedInteger('best_failed_floor')->nullable();
            $table->unsignedBigInteger('best_run_id')->nullable();
            $table->dateTime('achieved_at')->nullable();
            $table->unsignedInteger('total_runs')->default(0);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_defeats')->default(0);
            $table->unsignedInteger('total_returns')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'tower_key']);
        });

        $this->app->instance(CharacterStatusService::class, new class extends CharacterStatusService
        {
            public function getFinalStats(Character $character): array
            {
                return [
                    'max_hp' => 1234,
                    'max_mp' => 456,
                    'str' => 100,
                    'def' => 80,
                    'agi' => 70,
                    'mag' => 60,
                    'spr' => 50,
                    'luk' => 40,
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ([
            'tower_run_events',
            'tower_character_records',
            'tower_weekly_records',
            'tower_runs',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_start_run_initializes_tower_hp_mp_and_counts_one_run(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 12:00:00', 'Asia/Tokyo'));

        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);

        $run = $service->startRun($character);
        $sameRun = $service->startRun($character);

        $this->assertSame($run->id, $sameRun->id);
        $this->assertSame('running', $run->status);
        $this->assertSame('2026-W28', $run->season_key);
        $this->assertSame(1, $run->current_floor);
        $this->assertSame(0, $run->cleared_floor);
        $this->assertSame(1234, $run->tower_max_hp);
        $this->assertSame(1234, $run->tower_current_hp);
        $this->assertSame(456, $run->tower_max_mp);
        $this->assertSame(456, $run->tower_current_mp);
        $this->assertSame(1, TowerRun::query()->count());
        $this->assertSame(1, TowerCharacterRecord::query()->value('total_runs'));
    }

    public function test_disabled_tower_cannot_be_accessed_or_started(): void
    {
        config([
            'star_tree_tower.star_tree.enabled' => false,
            'extra_content.contents.star_tree_tower.default_enabled' => false,
        ]);

        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->canAccess($character));

        $this->expectException(RuntimeException::class);
        $service->startRun($character);
    }

    public function test_record_floor_cleared_updates_run_and_best_records(): void
    {
        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);
        $run = $service->startRun($character);

        $updated = $service->recordFloorCleared($character, $run, 1, 1000, 400, 1);

        $this->assertSame(1, $updated->cleared_floor);
        $this->assertSame(2, $updated->current_floor);
        $this->assertSame(2, $updated->reached_floor);
        $this->assertSame(1000, $updated->tower_current_hp);
        $this->assertSame(400, $updated->tower_current_mp);
        $this->assertSame(1, $updated->total_wins);
        $this->assertSame(1, $updated->stamina_spent);
        $this->assertSame(1, TowerWeeklyRecord::query()->value('best_cleared_floor'));
        $this->assertSame(1, TowerCharacterRecord::query()->value('best_cleared_floor'));
        $this->assertSame(1, TowerCharacterRecord::query()->value('total_wins'));
    }

    public function test_start_run_resumes_from_ten_floor_checkpoint_after_previous_record(): void
    {
        $character = $this->createCharacter();
        TowerCharacterRecord::query()->create([
            'character_id' => $character->id,
            'tower_key' => 'star_tree_tower',
            'best_cleared_floor' => 36,
            'total_runs' => 2,
            'total_wins' => 36,
            'total_defeats' => 1,
            'total_returns' => 1,
        ]);

        $service = app(StarTreeTowerService::class);
        $run = $service->startRun($character);

        $this->assertSame(31, $service->checkpointStartFloor($character));
        $this->assertSame(31, $run->current_floor);
        $this->assertSame(31, $run->reached_floor);
        $this->assertSame(30, $run->cleared_floor);
        $this->assertSame(1234, $run->tower_current_hp);
        $this->assertSame(456, $run->tower_current_mp);
        $this->assertSame(3, TowerCharacterRecord::query()->value('total_runs'));
    }

    public function test_return_from_tower_finishes_run_and_counts_return(): void
    {
        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);
        $run = $service->recordFloorCleared($character, $service->startRun($character), 1);

        $returned = $service->returnFromTower($character, $run);

        $this->assertSame('returned', $returned->status);
        $this->assertNotNull($returned->ended_at);
        $this->assertSame(1, TowerCharacterRecord::query()->value('total_returns'));
        $this->assertDatabaseHas('tower_run_events', [
            'tower_run_id' => $run->id,
            'event_type' => 'return',
            'result' => 'returned',
            'floor' => 1,
        ]);
    }

    public function test_pause_run_keeps_current_floor_and_running_status(): void
    {
        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);
        $run = $service->recordFloorCleared($character, $service->startRun($character), 1, 900, 300);

        $paused = $service->pauseRun($character, $run);

        $this->assertSame('running', $paused->status);
        $this->assertSame(2, $paused->current_floor);
        $this->assertSame(1, $paused->cleared_floor);
        $this->assertSame(900, $paused->tower_current_hp);
        $this->assertSame(300, $paused->tower_current_mp);
        $this->assertSame('pause', $paused->last_event_type);
        $this->assertSame(0, TowerCharacterRecord::query()->value('total_returns'));
        $this->assertDatabaseHas('tower_run_events', [
            'tower_run_id' => $run->id,
            'event_type' => 'pause',
            'result' => 'paused',
            'floor' => 2,
        ]);
    }

    public function test_restart_from_first_floor_closes_active_run_and_starts_fresh_run(): void
    {
        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);
        $run = $service->startRun($character);
        $run->forceFill([
            'current_floor' => 5,
            'reached_floor' => 5,
            'cleared_floor' => 4,
            'tower_current_hp' => 500,
            'tower_current_mp' => 100,
            'pending_event' => 'merchant',
        ])->save();

        $restart = $service->restartFromFirstFloor($character);
        $run->refresh();

        $this->assertSame('returned', $run->status);
        $this->assertSame('restart', $run->last_event_type);
        $this->assertNotNull($run->ended_at);
        $this->assertSame('running', $restart->status);
        $this->assertSame(1, $restart->current_floor);
        $this->assertSame(1, $restart->reached_floor);
        $this->assertSame(0, $restart->cleared_floor);
        $this->assertSame(1234, $restart->tower_current_hp);
        $this->assertSame(456, $restart->tower_current_mp);
        $this->assertNull($restart->pending_event);
        $this->assertSame(1, TowerRun::query()->where('status', 'running')->count());
        $this->assertSame(2, TowerCharacterRecord::query()->value('total_runs'));
        $this->assertSame(0, TowerCharacterRecord::query()->value('total_returns'));
        $this->assertDatabaseHas('tower_run_events', [
            'tower_run_id' => $run->id,
            'event_type' => 'restart',
            'result' => 'restarted',
            'floor' => 5,
        ]);
    }

    public function test_defeat_finishes_run_and_records_failed_floor(): void
    {
        $character = $this->createCharacter();
        $service = app(StarTreeTowerService::class);
        $run = $service->startRun($character);

        $defeated = $service->finishAsDefeated($character, $run, 1, 0, 30);

        $this->assertSame('defeated', $defeated->status);
        $this->assertSame(1, $defeated->failed_floor);
        $this->assertSame(0, $defeated->tower_current_hp);
        $this->assertSame(30, $defeated->tower_current_mp);
        $this->assertSame(1, $defeated->total_losses);
        $this->assertSame(0, TowerWeeklyRecord::query()->value('best_cleared_floor'));
        $this->assertSame(1, TowerWeeklyRecord::query()->value('best_failed_floor'));
        $this->assertSame(1, TowerCharacterRecord::query()->value('total_defeats'));
        $this->assertDatabaseHas('tower_run_events', [
            'tower_run_id' => $run->id,
            'event_type' => 'defeat',
            'result' => 'defeated',
            'floor' => 1,
        ]);
    }

    private function createCharacter(): Character
    {
        return Character::query()->create([
            'name' => 'Tower Tester',
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);
    }
}
