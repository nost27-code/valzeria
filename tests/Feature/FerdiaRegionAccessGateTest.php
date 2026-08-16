<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterCityDiscovery;
use App\Models\City;
use App\Models\JobClass;
use App\Models\User;
use App\Services\AreaService;
use App\Services\FerdiaMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FerdiaRegionAccessGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'extra_content.contents.ferdia_unlocked.default_enabled' => true,
            'ferdia_world_map.entry_requirement.area_id' => 70,
        ]);

        City::query()->updateOrCreate(['id' => 1], [
            'name' => '王都アークレア',
            'slug' => 'arclea',
            'sort_order' => 1,
            'is_initial' => true,
        ]);
        City::query()->updateOrCreate(['id' => 10], [
            'name' => '魔王城ヴァルゼリア',
            'slug' => 'valzeria-castle',
            'sort_order' => 10,
        ]);
        City::query()->updateOrCreate(['id' => 101], [
            'name' => '辺境の町ルヴァン',
            'slug' => 'luvan',
            'sort_order' => 110,
        ]);
        Area::query()->updateOrCreate(['id' => 70], [
            'city_id' => 10,
            'name' => '終焉の祭壇',
            'slug' => 'final-altar',
            'sort_order' => 7,
        ]);
        Area::query()->updateOrCreate(['id' => 1001], [
            'city_id' => 101,
            'name' => 'フェルディア南岸',
            'slug' => 'ferdia-south-coast',
            'sort_order' => 1,
        ]);
    }

    public function test_ferdia_map_and_direct_area_access_require_valzeria_final_boss_clear(): void
    {
        $character = $this->character(currentCityId: 10, highestCityId: 10);
        $ferdia = app(FerdiaMapService::class);
        $ferdiaCity = City::query()->findOrFail(101);
        CharacterCityDiscovery::query()->create([
            'character_id' => $character->id,
            'city_id' => $ferdiaCity->id,
            'discovery_state' => 'discovered',
        ]);

        $this->assertFalse($ferdia->canAccessRegion($character));
        $this->assertNull($ferdia->mapFor($character));
        $this->assertFalse($ferdia->canAccessArea($character, 1001));
        $this->assertFalse($ferdia->canTravelCity($character, $ferdiaCity));
        $this->assertFalse(app(AreaService::class)->canEnterArea($character, 1001));
        $this->assertDatabaseMissing('character_area_progresses', [
            'character_id' => $character->id,
            'area_id' => 1001,
        ]);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('city.index'))
            ->assertOk()
            ->assertDontSee('フェルディア大陸');

        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 70,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $this->assertTrue($ferdia->canAccessRegion($character));
        $this->assertNotNull($ferdia->mapFor($character));
        $this->assertTrue($ferdia->canAccessArea($character, 1001));
        $this->assertTrue($ferdia->canTravelCity($character, $ferdiaCity));
        $this->assertTrue(app(AreaService::class)->canEnterArea($character, 1001));
        $this->assertDatabaseHas('character_area_progresses', [
            'character_id' => $character->id,
            'area_id' => 1001,
            'is_unlocked' => true,
        ]);
        $this->get(route('city.index'))
            ->assertOk()
            ->assertSee('フェルディア大陸');
    }

    public function test_ineligible_character_already_in_ferdia_is_returned_to_valzeria(): void
    {
        $character = $this->character(currentCityId: 101, highestCityId: 10);

        $this->assertTrue(app(FerdiaMapService::class)->relocateFromDisabledRegion($character));
        $this->assertSame(10, (int) $character->fresh()->current_city_id);
    }

    public function test_global_publication_switch_still_blocks_a_cleared_character_when_off(): void
    {
        config(['extra_content.contents.ferdia_unlocked.default_enabled' => false]);
        $character = $this->character(currentCityId: 10, highestCityId: 10);
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 70,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $this->assertFalse(app(FerdiaMapService::class)->canAccessRegion($character));
        $this->assertNull(app(FerdiaMapService::class)->mapFor($character));
    }

    private function character(int $currentCityId, int $highestCityId): Character
    {
        $job = JobClass::query()->firstOrCreate(
            ['key' => 'ferdia-access-test-job'],
            ['name' => '旅人', 'rank' => 'basic']
        );

        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'フェルディア条件確認者',
            'current_job_id' => $job->id,
            'current_city_id' => $currentCityId,
            'highest_city_id' => $highestCityId,
            'current_hp' => 100,
            'current_mp' => 50,
        ]);
    }
}
