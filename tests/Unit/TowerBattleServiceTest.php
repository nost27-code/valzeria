<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\TowerFloorMaster;
use App\Models\TowerMerchantPurchase;
use App\Models\TowerRunEvent;
use App\Services\CharacterStatusService;
use App\Services\ExplorationStaminaService;
use App\Services\JobArtService;
use App\Services\TowerEnemyScalingService;
use App\Services\StarTreeTowerService;
use App\Services\TowerBattleService;
use App\Services\ValmonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TowerBattleServiceTest extends TestCase
{
    public int $towerEnemyHp = 10;
    public int $towerEnemyAttack = 1;
    public int $staminaCurrent = 10;

    protected function setUp(): void
    {
        parent::setUp();

        config(['star_tree_tower.star_tree.enabled' => true]);

        foreach ([
            'tower_run_events',
            'tower_merchant_purchases',
            'tower_character_records',
            'tower_weekly_records',
            'tower_runs',
            'tower_floor_master',
            'character_items',
            'items',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('exp')->default(0);
            $table->unsignedBigInteger('money')->default(0);
            $table->unsignedBigInteger('current_job_id')->nullable();
            $table->unsignedInteger('hp_base')->default(100);
            $table->unsignedInteger('mp_base')->default(30);
            $table->unsignedInteger('attack_base')->default(10);
            $table->unsignedInteger('defense_base')->default(8);
            $table->unsignedInteger('speed_base')->default(8);
            $table->unsignedInteger('magic_base')->default(8);
            $table->unsignedInteger('spirit_base')->default(8);
            $table->unsignedInteger('luck_base')->default(5);
            $table->unsignedInteger('current_hp')->default(100);
            $table->unsignedInteger('current_mp')->default(30);
            $table->decimal('hp_fraction', 8, 4)->default(0);
            $table->decimal('mp_fraction', 8, 4)->default(0);
            $table->decimal('attack_fraction', 8, 4)->default(0);
            $table->decimal('defense_fraction', 8, 4)->default(0);
            $table->decimal('speed_fraction', 8, 4)->default(0);
            $table->decimal('magic_fraction', 8, 4)->default(0);
            $table->decimal('spirit_fraction', 8, 4)->default(0);
            $table->decimal('luck_fraction', 8, 4)->default(0);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('highest_city_id')->default(1);
            $table->unsignedInteger('equipment_storage_limit')->default(300);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamp('last_battle_at')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('character_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('item_id');
            $table->boolean('is_equipped')->default(false);
            $table->string('equipped_slot', 50)->nullable();
            $table->string('killer_species_key', 32)->nullable();
            $table->decimal('killer_damage_rate', 5, 4)->default(0);
            $table->string('resist_species_key', 32)->nullable();
            $table->decimal('species_damage_reduction_rate', 5, 4)->default(0);
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

        Schema::create('tower_merchant_purchases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tower_run_id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedInteger('floor');
            $table->string('item_key', 100);
            $table->string('item_name', 100);
            $table->unsignedInteger('price')->default(0);
            $table->string('effect_type', 50);
            $table->unsignedInteger('effect_value')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('used_at')->nullable();
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

        Schema::create('tower_floor_master', function (Blueprint $table): void {
            $table->id();
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->unsignedInteger('floor');
            $table->string('layer_key', 100);
            $table->string('layer_name', 100);
            $table->string('enemy_name', 100);
            $table->string('enemy_profile', 50)->default('physical');
            $table->string('enemy_type_name', 50)->nullable();
            $table->unsignedInteger('stamina_cost')->default(1);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->app->instance(CharacterStatusService::class, new class extends CharacterStatusService
        {
            public function getFinalStats(Character $character): array
            {
                return [
                    'max_hp' => 1000,
                    'max_mp' => 100,
                    'str' => 500,
                    'def' => 200,
                    'agi' => 100,
                    'mag' => 100,
                    'spr' => 100,
                    'luk' => 100,
                ];
            }
        });

        $this->app->instance(JobArtService::class, new class extends JobArtService
        {
            public function battleArtsFor(Character $character, string $context = 'pve'): Collection
            {
                return collect();
            }
        });

        $test = $this;
        $this->app->instance(TowerEnemyScalingService::class, new class($test) extends TowerEnemyScalingService
        {
            public function __construct(private TowerBattleServiceTest $test)
            {
            }

            public function statsForFloorMaster(TowerFloorMaster $floorMaster): array
            {
                return [
                    'max_hp' => $this->test->towerEnemyHp,
                    'str' => $this->test->towerEnemyAttack,
                    'def' => 0,
                    'mag' => 0,
                    'spr' => 0,
                    'agi' => 1,
                    'luk' => 1,
                ];
            }
        });

        $this->app->instance(ExplorationStaminaService::class, new class($test) extends ExplorationStaminaService
        {
            public function __construct(private TowerBattleServiceTest $test)
            {
            }

            public function consume(Character $character, int $cost, string $errorMessage = '探索力が足りません。回復を待ってください。'): array
            {
                if ($this->test->staminaCurrent < $cost) {
                    return ['ok' => false, 'consumed' => 0, 'stamina' => null, 'error' => $errorMessage];
                }

                $this->test->staminaCurrent -= $cost;

                return ['ok' => true, 'consumed' => $cost, 'stamina' => null];
            }
        });

        $this->app->instance(ValmonService::class, new class extends ValmonService
        {
            public function partnerFor(Character $character): ?\App\Models\PlayerValmon
            {
                return null;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'tower_run_events',
            'tower_merchant_purchases',
            'tower_character_records',
            'tower_weekly_records',
            'tower_runs',
            'tower_floor_master',
            'character_items',
            'items',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_challenge_current_floor_records_victory_and_advances_run(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 4);
        $run = app(StarTreeTowerService::class)->startRun($character);
        $run->forceFill([
            'tower_current_hp' => 800,
            'tower_current_mp' => 80,
        ])->save();

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run);
        $run->refresh();

        $this->assertSame('victory', $event->result);
        $this->assertSame('battle', $event->event_type);
        $this->assertSame(4, $event->stamina_delta);
        $this->assertSame(60, $event->exp_gained);
        $this->assertSame(1, $event->job_exp_gained);
        $this->assertSame(1, $run->cleared_floor);
        $this->assertSame(2, $run->current_floor);
        $this->assertGreaterThanOrEqual(800, $event->hp_after);
        $this->assertLessThanOrEqual(850, $event->hp_after);
        $this->assertSame(85, $event->mp_after);
        $this->assertSame(4, $run->stamina_spent);
        $this->assertSame(6, $this->staminaCurrent);
        $this->assertSame(1, TowerRunEvent::query()->where('event_type', 'battle')->count());
        $this->assertTrue(collect($event->metadata['logs'])->contains(
            fn (string $log): bool => str_contains($log, '星樹の息吹が巡り、HPが50、SPが5回復した。')
                && str_contains($log, 'color:#0d9488')
        ));
        $character->refresh();
        $this->assertSame(2, (int) $character->level);
        $this->assertSame(10, (int) $character->exp);
        $this->assertNotNull($character->last_battle_at);
    }

    public function test_defeat_finishes_run_and_records_battle_and_defeat_events(): void
    {
        $this->towerEnemyHp = 99999;
        $this->towerEnemyAttack = 9999;

        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run);
        $run->refresh();

        $this->assertContains($event->result, ['defeat', 'timeout']);
        $this->assertSame('defeated', $run->status);
        $this->assertSame(1, $run->failed_floor);
        $this->assertSame(2, $run->stamina_spent);
        $this->assertSame(1, TowerRunEvent::query()->where('event_type', 'battle')->count());
        $this->assertSame(1, TowerRunEvent::query()->where('event_type', 'defeat')->count());
    }

    public function test_not_enough_stamina_does_not_create_battle_event(): void
    {
        $this->staminaCurrent = 1;

        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $this->expectException(RuntimeException::class);

        try {
            app(TowerBattleService::class)->challengeCurrentFloor($character, $run);
        } finally {
            $this->assertSame(0, TowerRunEvent::query()->where('event_type', 'battle')->count());
            $this->assertSame(0, $run->refresh()->stamina_spent);
        }
    }

    public function test_recent_battle_guard_blocks_duplicate_challenge_before_stamina_is_spent(): void
    {
        $character = $this->createCharacter([
            'last_battle_at' => now(),
        ]);
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $this->expectException(RuntimeException::class);

        try {
            app(TowerBattleService::class)->challengeCurrentFloor($character, $run);
        } finally {
            $this->assertSame(0, TowerRunEvent::query()->where('event_type', 'battle')->count());
            $this->assertSame(0, $run->refresh()->stamina_spent);
            $this->assertSame(10, $this->staminaCurrent);
        }
    }

    public function test_player_actor_includes_equipped_killer_and_resist_affixes(): void
    {
        $character = $this->createCharacter();
        $weapon = Item::query()->create([
            'name' => '妖精斬りの剣',
            'type' => 'weapon',
            'is_active' => true,
        ]);
        $armor = Item::query()->create([
            'name' => '妖精護りの鎧',
            'type' => 'armor',
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weapon->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'killer_species_key' => '妖精',
            'killer_damage_rate' => 0.08,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $armor->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'resist_species_key' => '妖精',
            'species_damage_reduction_rate' => 0.05,
        ]);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $method = new \ReflectionMethod(TowerBattleService::class, 'makePlayerActor');
        $method->setAccessible(true);
        $actor = $method->invoke(app(TowerBattleService::class), $character, $run);

        $this->assertSame('妖精', $actor->weaponKillerSpeciesKey);
        $this->assertSame(0.08, $actor->weaponKillerDamageRate);
        $this->assertSame('妖精', $actor->armorResistSpeciesKey);
        $this->assertSame(0.05, $actor->armorSpeciesDamageReductionRate);
    }

    public function test_cautious_strategy_spends_one_extra_stamina(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 4);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run, true, 'cautious');

        $this->assertSame('battle', $event->event_type);
        $this->assertSame(5, $event->stamina_delta);
        $this->assertSame(5, $this->staminaCurrent);
        $this->assertSame('cautious', $event->metadata['strategy']['key']);
    }

    public function test_full_force_strategy_grants_extra_exp(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run, true, 'full_force');

        $this->assertSame('victory', $event->result);
        $this->assertSame(69, $event->exp_gained);
        $this->assertSame('full_force', $event->metadata['strategy']['key']);
        $this->assertGreaterThan(0, (int) $event->metadata['pre_battle']['sp_spent']);
    }

    public function test_scout_strategy_reveals_enemy_without_battle(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 4);
        $run = app(StarTreeTowerService::class)->startRun($character);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run, true, 'scout');
        $run->refresh();

        $this->assertSame('scout', $event->event_type);
        $this->assertSame('scouted', $event->result);
        $this->assertSame(1, $event->stamina_delta);
        $this->assertSame(9, $this->staminaCurrent);
        $this->assertSame(1, $run->current_floor);
        $this->assertSame(0, $run->cleared_floor);
        $this->assertSame(0, TowerRunEvent::query()->where('event_type', 'battle')->count());
    }

    public function test_kodama_ward_is_consumed_on_next_battle(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);
        $purchase = TowerMerchantPurchase::query()->create([
            'tower_run_id' => $run->id,
            'character_id' => $character->id,
            'floor' => 1,
            'item_key' => 'kodama_ward',
            'item_name' => '木霊の護符',
            'price' => 1200,
            'effect_type' => 'damage_reduction_next',
            'effect_value' => 20,
            'activated_at' => now(),
        ]);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run);

        $this->assertSame('battle', $event->event_type);
        $this->assertNotNull($purchase->refresh()->used_at);
        $this->assertSame(20, $event->metadata['ward']['damage_reduction_rate']);
    }

    public function test_kodama_ward_is_not_consumed_before_manual_use(): void
    {
        $character = $this->createCharacter();
        $this->createFloor(staminaCost: 2);
        $run = app(StarTreeTowerService::class)->startRun($character);
        $purchase = TowerMerchantPurchase::query()->create([
            'tower_run_id' => $run->id,
            'character_id' => $character->id,
            'floor' => 1,
            'item_key' => 'kodama_ward',
            'item_name' => '木霊の護符',
            'price' => 1200,
            'effect_type' => 'damage_reduction_next',
            'effect_value' => 20,
        ]);

        $event = app(TowerBattleService::class)->challengeCurrentFloor($character, $run);

        $this->assertSame('battle', $event->event_type);
        $this->assertNull($purchase->refresh()->used_at);
        $this->assertNull($event->metadata['ward']);
    }

    private function createCharacter(array $overrides = []): Character
    {
        return Character::query()->create($overrides + [
            'name' => 'Tower Battler',
            'level' => 1,
            'exp' => 0,
            'money' => 0,
            'hp_base' => 100,
            'mp_base' => 30,
            'attack_base' => 10,
            'defense_base' => 8,
            'speed_base' => 8,
            'magic_base' => 8,
            'spirit_base' => 8,
            'luck_base' => 5,
            'current_hp' => 100,
            'current_mp' => 30,
            'wins' => 0,
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);
    }

    private function createFloor(int $staminaCost = 1): TowerFloorMaster
    {
        return TowerFloorMaster::query()->create([
            'tower_key' => 'star_tree_tower',
            'floor' => 1,
            'layer_key' => 'sprout',
            'layer_name' => '若葉層',
            'enemy_name' => '若葉の影',
            'enemy_profile' => 'physical',
            'enemy_type_name' => '標準型',
            'stamina_cost' => $staminaCost,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
