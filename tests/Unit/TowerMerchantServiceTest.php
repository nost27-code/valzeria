<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\TowerMerchantPurchase;
use App\Models\TowerRun;
use App\Services\TowerMerchantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TowerMerchantServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'tower_merchant_purchases',
            'tower_run_events',
            'tower_runs',
            'gold_transactions',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('money')->default(0);
            $table->unsignedInteger('highest_city_id')->default(1);
            $table->unsignedInteger('equipment_storage_limit')->default(300);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gold_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('type');
            $table->integer('amount');
            $table->integer('balance_after')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note')->nullable();
            $table->json('metadata')->nullable();
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
            $table->unsignedInteger('price');
            $table->string('effect_type', 50);
            $table->unsignedInteger('effect_value');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'tower_merchant_purchases',
            'tower_run_events',
            'tower_runs',
            'gold_transactions',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_buy_hp_item_spends_gold_and_stores_recovery_item(): void
    {
        config([
            'star_tree_tower.star_tree.merchant_hp_item_price' => 500,
            'star_tree_tower.star_tree.merchant_hp_item_recover_rate' => 25,
        ]);
        $character = $this->createCharacter(1000);
        $run = $this->createRun($character, hp: 400, mp: 20);

        $event = app(TowerMerchantService::class)->buy($character, $run, 'star_leaf_herb');
        $run->refresh();
        $character->refresh();

        $this->assertSame('merchant_purchase', $event->event_type);
        $this->assertSame(400, $run->tower_current_hp);
        $this->assertSame(500, $run->gold_spent);
        $this->assertSame(500, $character->money);
        $this->assertSame(1, TowerMerchantPurchase::query()->count());
        $this->assertNull(TowerMerchantPurchase::query()->first()->used_at);
        $this->assertSame([
            [
                'purchase_id' => (int) TowerMerchantPurchase::query()->value('id'),
                'key' => 'star_leaf_herb',
                'name' => '星葉の薬草',
                'description' => 'HP25%',
                'count' => 1,
                'effect_type' => 'hp_recover_rate',
                'usable' => true,
                'armed' => false,
            ],
        ], app(TowerMerchantService::class)->availableRecoveryItems($run));
        $products = collect(app(TowerMerchantService::class)->products($run))->keyBy('key');
        $this->assertTrue($products['star_leaf_herb']['purchased']);
        $this->assertFalse($products['moon_dew_vial']['purchased']);
        $this->assertDatabaseHas('gold_transactions', [
            'character_id' => $character->id,
            'type' => 'tower_merchant',
            'amount' => -500,
        ]);
    }

    public function test_use_purchased_hp_item_recovers_tower_hp_and_marks_used(): void
    {
        config([
            'star_tree_tower.star_tree.merchant_hp_item_price' => 500,
            'star_tree_tower.star_tree.merchant_hp_item_recover_rate' => 25,
        ]);
        $character = $this->createCharacter(1000);
        $run = $this->createRun($character, hp: 400, mp: 20);

        app(TowerMerchantService::class)->buy($character, $run, 'star_leaf_herb');
        $purchase = TowerMerchantPurchase::query()->firstOrFail();

        $event = app(TowerMerchantService::class)->usePurchasedItem($character, $run, $purchase);
        $run->refresh();
        $purchase->refresh();

        $this->assertSame('merchant_item_use', $event->event_type);
        $this->assertSame(650, $run->tower_current_hp);
        $this->assertNotNull($purchase->used_at);
        $this->assertSame([], app(TowerMerchantService::class)->availableRecoveryItems($run));
    }

    public function test_buy_ward_item_is_listed_as_manually_usable_next_battle_item(): void
    {
        config([
            'star_tree_tower.star_tree.merchant_ward_item_price' => 1200,
            'star_tree_tower.star_tree.merchant_ward_damage_reduction_rate' => 20,
        ]);
        $character = $this->createCharacter(2000);
        $run = $this->createRun($character);

        app(TowerMerchantService::class)->buy($character, $run, 'kodama_ward');
        $run->refresh();

        $this->assertSame([
            [
                'purchase_id' => (int) TowerMerchantPurchase::query()->value('id'),
                'key' => 'kodama_ward',
                'name' => '木霊の護符',
                'description' => '次戦闘の被ダメ-20%',
                'count' => 1,
                'effect_type' => 'damage_reduction_next',
                'usable' => true,
                'armed' => false,
            ],
        ], app(TowerMerchantService::class)->availableRecoveryItems($run));
    }

    public function test_use_ward_item_arms_next_battle_without_consuming_it(): void
    {
        config([
            'star_tree_tower.star_tree.merchant_ward_item_price' => 1200,
            'star_tree_tower.star_tree.merchant_ward_damage_reduction_rate' => 20,
        ]);
        $character = $this->createCharacter(2000);
        $run = $this->createRun($character);

        app(TowerMerchantService::class)->buy($character, $run, 'kodama_ward');
        $purchase = TowerMerchantPurchase::query()->firstOrFail();

        $event = app(TowerMerchantService::class)->usePurchasedItem($character, $run, $purchase);
        $purchase->refresh();

        $this->assertSame('merchant_item_use', $event->event_type);
        $this->assertNotNull($purchase->activated_at);
        $this->assertNull($purchase->used_at);
        $this->assertSame([
            [
                'purchase_id' => (int) $purchase->id,
                'key' => 'kodama_ward',
                'name' => '木霊の護符',
                'description' => '次戦闘の被ダメ-20%',
                'count' => 1,
                'effect_type' => 'damage_reduction_next',
                'usable' => false,
                'armed' => true,
            ],
        ], app(TowerMerchantService::class)->availableRecoveryItems($run));
    }

    public function test_skip_clears_pending_event(): void
    {
        $character = $this->createCharacter(1000);
        $run = $this->createRun($character);

        $event = app(TowerMerchantService::class)->skip($character, $run);

        $this->assertSame('skipped', $event->result);
        $this->assertNull($run->refresh()->pending_event);
    }

    private function createCharacter(int $money): Character
    {
        return Character::query()->create([
            'name' => 'Merchant Tester',
            'money' => $money,
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);
    }

    private function createRun(Character $character, int $hp = 400, int $mp = 20): TowerRun
    {
        return TowerRun::query()->create([
            'character_id' => $character->id,
            'tower_key' => 'star_tree_tower',
            'season_key' => '2026-W28',
            'status' => 'running',
            'current_floor' => 3,
            'reached_floor' => 3,
            'cleared_floor' => 2,
            'tower_max_hp' => 1000,
            'tower_current_hp' => $hp,
            'tower_max_mp' => 100,
            'tower_current_mp' => $mp,
            'last_merchant_floor' => 2,
            'pending_event' => 'merchant',
            'started_at' => now(),
        ]);
    }
}
