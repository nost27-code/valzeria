<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\TowerRewardClaim;
use App\Services\StarTreeTowerRewardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StarTreeTowerRewardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'public_logs',
            'character_adventurer_card_assets',
            'tower_reward_claims',
            'character_items',
            'items',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50);
            $table->string('external_item_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('character_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('item_id');
            $table->string('affix_quality')->nullable();
            $table->string('killer_species_key', 32)->nullable();
            $table->decimal('killer_damage_rate', 5, 4)->default(0);
            $table->boolean('is_equipped')->default(false);
            $table->boolean('is_stored')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->unsignedTinyInteger('enhance_level')->default(0);
            $table->string('acquired_from')->nullable();
            $table->timestamps();
        });

        Schema::create('tower_reward_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('tower_key', 80)->default('star_tree_tower');
            $table->unsignedSmallInteger('floor');
            $table->string('reward_type', 40);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('selected_item_id')->nullable();
            $table->unsignedBigInteger('character_item_id')->nullable();
            $table->string('asset_type', 40)->nullable();
            $table->string('asset_path', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'tower_key', 'floor', 'reward_type'], 'tower_reward_claim_unique');
        });

        Schema::create('character_adventurer_card_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('asset_type', 40);
            $table->string('asset_path', 120);
            $table->string('source', 40)->default('default');
            $table->timestamp('obtained_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'asset_type', 'asset_path'], 'character_card_assets_unique');
        });

        Schema::create('public_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50);
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->text('message');
            $table->unsignedTinyInteger('importance')->default(1);
            $table->timestamps();
        });

        foreach ([50, 70, 90] as $floor) {
            foreach (array_keys(config("star_tree_tower_rewards.weapon_rewards.{$floor}.weapons", [])) as $category) {
                Item::query()->create([
                    'name' => (string) config("star_tree_tower_rewards.weapon_rewards.{$floor}.weapons.{$category}.name"),
                    'type' => 'weapon',
                    'external_item_id' => 'STAR_TREE_TOWER_'.$floor.'_'.strtoupper((string) $category),
                    'is_active' => true,
                ]);
            }
        }
    }

    public function test_it_creates_pending_first_clear_rewards_once(): void
    {
        $character = Character::query()->create(['name' => 'Reward Tester']);
        $service = app(StarTreeTowerRewardService::class);

        $created = $service->createPendingRewardsForClearedFloor($character, 90);
        $service->createPendingRewardsForClearedFloor($character, 90);

        $this->assertSame(4, TowerRewardClaim::query()->count());
        $this->assertSame(3, TowerRewardClaim::query()->where('status', StarTreeTowerRewardService::STATUS_PENDING)->count());
        $this->assertTrue(collect($created)->contains(
            fn (array $reward): bool => $reward['reward_type'] === StarTreeTowerRewardService::TYPE_CARD_BACKGROUND
                && $reward['name'] === '冒険者カード背景「エルフィア」'
        ));
        $this->assertSame(
            [50, 50, 70, 90],
            TowerRewardClaim::query()->orderBy('floor')->orderBy('reward_type')->pluck('floor')->all()
        );
        $this->assertTrue(TowerRewardClaim::query()
            ->where('floor', 50)
            ->where('reward_type', StarTreeTowerRewardService::TYPE_CARD_BACKGROUND)
            ->where('status', StarTreeTowerRewardService::STATUS_CLAIMED)
            ->exists());
        $this->assertFalse($service->pendingRewardsFor($character)
            ->contains(fn (array $reward): bool => $reward['reward_type'] === StarTreeTowerRewardService::TYPE_CARD_BACKGROUND));
    }

    public function test_it_claims_selected_weapon_as_normal_quality_plant_killer_reward(): void
    {
        $character = Character::query()->create(['name' => 'Reward Tester']);
        $service = app(StarTreeTowerRewardService::class);
        $service->createPendingRewardsForClearedFloor($character, 50);
        $claim = TowerRewardClaim::query()->firstOrFail();

        $result = $service->claim($claim, $character, 'sword');

        $characterItem = $result['character_item'];
        $this->assertInstanceOf(CharacterItem::class, $characterItem);
        $this->assertSame('星葉の剣', $characterItem->item->name);
        $this->assertNull($characterItem->affix_quality);
        $this->assertSame('plant', $characterItem->killer_species_key);
        $this->assertSame(0.03, (float) $characterItem->killer_damage_rate);
        $this->assertTrue((bool) $characterItem->is_locked);
        $this->assertSame('claimed', TowerRewardClaim::query()->first()->status);

        $this->expectException(RuntimeException::class);
        $service->claim(TowerRewardClaim::query()->first(), $character, 'sword');
    }

    public function test_it_auto_claims_elphia_card_background_reward(): void
    {
        $character = Character::query()->create(['name' => 'Reward Tester']);
        $service = app(StarTreeTowerRewardService::class);
        $service->createPendingRewardsForClearedFloor($character, 50);
        $claim = TowerRewardClaim::query()
            ->where('reward_type', StarTreeTowerRewardService::TYPE_CARD_BACKGROUND)
            ->firstOrFail();

        $this->assertSame(StarTreeTowerRewardService::STATUS_CLAIMED, $claim->refresh()->status);
        $this->assertTrue(DB::table('character_adventurer_card_assets')
            ->where('character_id', $character->id)
            ->where('asset_type', 'background')
            ->where('asset_path', 'images/profile/adventurer_card_bg03.webp')
            ->exists());
        $this->assertSame(0, DB::table('public_logs')->where('type', 'tower')->count());
    }

    public function test_it_claims_elphia_card_decoration_frame_set_reward(): void
    {
        $character = Character::query()->create(['name' => 'Reward Tester']);
        $service = app(StarTreeTowerRewardService::class);
        $service->createPendingRewardsForClearedFloor($character, 100);
        $claim = TowerRewardClaim::query()
            ->where('reward_type', StarTreeTowerRewardService::TYPE_CARD_FRAME)
            ->firstOrFail();

        $service->claim($claim, $character);

        $this->assertSame('claimed', $claim->refresh()->status);
        $this->assertTrue(DB::table('character_adventurer_card_assets')
            ->where('character_id', $character->id)
            ->where('asset_type', 'card_frame')
            ->where('asset_path', 'images/profile/adventurer_card_frame03.webp')
            ->exists());
        $this->assertTrue(DB::table('character_adventurer_card_assets')
            ->where('character_id', $character->id)
            ->where('asset_type', 'avatar_frame')
            ->where('asset_path', 'images/profile/adventurer_avatar_frame03.webp')
            ->exists());
        $this->assertSame(1, DB::table('public_logs')->where('type', 'tower')->count());
        $this->assertFalse(DB::table('public_logs')->where('message', 'like', '%冒険者カード背景%')->exists());
        $this->assertFalse(DB::table('public_logs')->where('message', 'like', '%星梯の塔%')->exists());
        $this->assertTrue(DB::table('public_logs')->where('message', 'like', '【星樹の塔】%冒険者カード装飾%')->exists());
    }
}
