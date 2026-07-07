<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterTitle;
use App\Models\Title;
use App\Models\TowerCharacterRecord;
use App\Services\TowerTitleRewardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TowerTitleRewardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'tower_character_records',
            'character_titles',
            'titles',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('highest_city_id')->default(1);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->unsignedInteger('equipment_storage_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('titles', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->nullable();
            $table->string('rarity')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('hint')->nullable();
            $table->string('unlock_type')->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('source_master')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
        });

        Schema::create('character_titles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('title_id');
            $table->boolean('is_equipped')->default(false);
            $table->timestamps();
        });

        Schema::create('tower_character_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('tower_key');
            $table->string('season_key')->nullable();
            $table->unsignedInteger('best_cleared_floor')->default(0);
            $table->unsignedInteger('best_failed_floor')->default(0);
            $table->unsignedBigInteger('best_run_id')->nullable();
            $table->unsignedInteger('total_runs')->default(0);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_defeats')->default(0);
            $table->unsignedInteger('total_returns')->default(0);
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'tower_character_records',
            'character_titles',
            'titles',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_unlock_floor_milestones_grants_reached_tower_titles_once(): void
    {
        $character = Character::query()->create([
            'name' => 'Reward Tester',
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        $floor10 = $this->createTowerTitle(10, '星梯の一歩');
        $floor20 = $this->createTowerTitle(20, '若葉を越えし者');
        $this->createTowerTitle(30, '風枝の踏破者');

        $service = app(TowerTitleRewardService::class);

        $this->assertSame(['星梯の一歩', '若葉を越えし者'], $service->unlockFloorMilestones($character, 20));
        $this->assertSame([], $service->unlockFloorMilestones($character, 20));

        $this->assertSame(2, CharacterTitle::query()->count());
        $this->assertDatabaseHas('character_titles', [
            'character_id' => $character->id,
            'title_id' => $floor10->id,
        ]);
        $this->assertDatabaseHas('character_titles', [
            'character_id' => $character->id,
            'title_id' => $floor20->id,
        ]);
    }

    public function test_unlock_floor_milestones_grants_every_ten_floors(): void
    {
        $character = Character::query()->create([
            'name' => 'Reward Tester',
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        $expectedNames = [];
        foreach (range(10, 100, 10) as $floor) {
            $name = "星樹の塔{$floor}階踏破";
            $this->createTowerTitle($floor, $name);
            $expectedNames[] = $name;
        }

        $service = app(TowerTitleRewardService::class);

        $this->assertSame($expectedNames, $service->unlockFloorMilestones($character, 100));
        $this->assertSame(10, CharacterTitle::query()->where('character_id', $character->id)->count());
    }

    public function test_unlock_floor_milestones_ignores_non_tower_titles(): void
    {
        $character = Character::query()->create([
            'name' => 'Reward Tester',
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        Title::query()->create([
            'category' => '戦闘',
            'rarity' => 'normal',
            'name' => '戦いの証',
            'unlock_type' => 'battle_win_count',
            'target_type' => 'count',
            'target_id' => '10',
        ]);

        $this->assertSame([], app(TowerTitleRewardService::class)->unlockFloorMilestones($character, 20));
        $this->assertSame(0, CharacterTitle::query()->count());
    }

    public function test_unlock_earned_milestones_uses_existing_best_record(): void
    {
        $character = Character::query()->create([
            'name' => 'Reward Tester',
            'highest_city_id' => 5,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        $this->createTowerTitle(10, '星梯の一歩');
        $this->createTowerTitle(20, '若葉を越えし者');
        $this->createTowerTitle(30, '風枝の踏破者');

        TowerCharacterRecord::query()->create([
            'character_id' => $character->id,
            'tower_key' => 'star_tree_tower',
            'season_key' => '2026-W28',
            'best_cleared_floor' => 27,
            'achieved_at' => now(),
        ]);

        $service = app(TowerTitleRewardService::class);

        $this->assertSame(['星梯の一歩', '若葉を越えし者'], $service->unlockEarnedMilestones($character, 'star_tree_tower'));
        $this->assertSame(2, CharacterTitle::query()->where('character_id', $character->id)->count());
    }

    private function createTowerTitle(int $floor, string $name): Title
    {
        return Title::query()->create([
            'category' => '星樹の塔',
            'rarity' => 'normal',
            'name' => $name,
            'description' => "星樹の塔{$floor}階を踏破した証。",
            'hint' => "星樹の塔{$floor}階を踏破する",
            'unlock_type' => 'tower_floor_clear',
            'target_type' => 'tower_floor',
            'target_id' => (string) $floor,
            'source_master' => 'star_tree_tower',
            'display_order' => 1100 + $floor,
            'is_hidden' => false,
        ]);
    }
}
