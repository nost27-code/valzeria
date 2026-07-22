<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Area;
use App\Models\City;
use App\Models\PlayerValmon;
use App\Models\PlayerValmonEgg;
use App\Models\PublicLog;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ValmonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValmonEggStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_valmon_egg_is_stored_on_return_and_is_not_lost_afterward(): void
    {
        $character = $this->character();
        $master = $this->master('egg-storage-duplicate', 'テストヴァルモン');
        PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);
        $egg = PlayerValmonEgg::create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'found_at' => now(),
        ]);

        $resolved = app(ValmonService::class)->hatchActiveEggs($character);

        $this->assertSame([[
            'name' => 'テストヴァルモン',
            'rarity' => 'normal',
            'stored' => true,
        ]], $resolved);
        $this->assertDatabaseHas('player_valmon_eggs', [
            'id' => $egg->id,
            'is_hatched' => false,
            'is_lost' => false,
        ]);
        $this->assertNotNull($egg->fresh()->stored_at);
        $this->assertSame([], app(ValmonService::class)->loseActiveEggs($character));
        $this->assertSame(0, PublicLog::query()->where('type', 'valmon')->count());
    }

    public function test_unowned_valmon_egg_hatches_on_return(): void
    {
        $character = $this->character();
        $master = $this->master('egg-storage-new', '新しいヴァルモン');
        $egg = PlayerValmonEgg::create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'found_at' => now(),
        ]);

        $resolved = app(ValmonService::class)->hatchActiveEggs($character);

        $this->assertSame(false, $resolved[0]['stored']);
        $this->assertDatabaseHas('player_valmons', [
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
        ]);
        $this->assertTrue($egg->fresh()->is_hatched);
    }

    public function test_map_exploration_uses_the_same_daily_egg_limit(): void
    {
        $character = $this->character();
        $master = $this->master('map-egg-limit', '地図テストモン');
        $city = City::create(['name' => '卵試験街', 'description' => '', 'recommended_level_min' => 1, 'recommended_level_max' => 10, 'sort_order' => 1]);
        $area = Area::create(['name' => '卵試験地', 'slug' => 'map-egg-test', 'city_id' => $city->id, 'recommended_level_min' => 1, 'recommended_level_max' => 10]);
        PlayerValmonEgg::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'found_at' => now()]);

        $found = app(ValmonService::class)->tryFindEgg($character, $area, null);

        $this->assertNull($found);
        $this->assertSame(1, PlayerValmonEgg::where('character_id', $character->id)->count());
    }

    private function character(): Character
    {
        return Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '卵保管テスト',
        ]);
    }

    private function master(string $key, string $name): ValmonMaster
    {
        return ValmonMaster::create([
            'valmon_key' => $key,
            'name' => $name,
            'rarity' => 'normal',
            'is_active' => true,
        ]);
    }
}
