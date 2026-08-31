<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\MapExplorationBatch;
use App\Models\MapIncomeLog;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesExplorationMapEnemyFixtures;
use Tests\TestCase;

class AdminPublishedMapManagerTest extends TestCase
{
    use CreatesExplorationMapEnemyFixtures, RefreshDatabase;

    public function test_admin_can_review_only_currently_open_published_maps(): void
    {
        config()->set('exploration_maps.reward_profiles.ancient_fragment.weight', 0);
        $city = City::findOrFail(1);
        $area = Area::create([
            'name' => '管理公開地図試験地',
            'slug' => 'admin-published-map-test',
            'city_id' => $city->id,
            'recommended_level_min' => 45,
            'recommended_level_max' => 55,
        ]);
        $enemy = $this->createExplorationMapEnemyFixtures($area, '管理確認魔物', 50, [
            'max_hp' => 200,
            'str' => 40,
            'def' => 20,
            'agi' => 20,
            'mag' => 20,
            'spr' => 20,
            'luk' => 20,
            'exp_reward' => 50,
            'gold_reward' => 30,
        ])['normal'];
        $owner = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '公開地図の発見者',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 50000,
        ]);

        $openMap = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000101');
        $openRegistration = app(MapSurveyService::class)->start($owner, $openMap, $city);
        $openRegistration = app(MapPublicationService::class)->publish($owner, $openRegistration, 100);

        $closedMap = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000102');
        $closedRegistration = app(MapSurveyService::class)->start($owner, $closedMap, $city);
        $closedRegistration = app(MapPublicationService::class)->publish($owner, $closedRegistration, 100);
        $closedRegistration->update(['remaining_explorations' => 0]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.published-maps'))
            ->assertOk()
            ->assertSee('公開中の探索地図')
            ->assertSee($openMap->name)
            ->assertSee('管理確認魔物')
            ->assertSee('目安戦力')
            ->assertDontSee($closedMap->name);
    }

    public function test_non_admin_cannot_open_published_map_manager(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.published-maps'))
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_review_map_institute_development_by_city(): void
    {
        config()->set('exploration_maps.reward_profiles.ancient_fragment.weight', 0);

        $city = City::findOrFail(1);
        City::whereKey($city->id)->update(['map_institute_development' => 3200]);

        $area = Area::create([
            'name' => '地図院発展値試験地',
            'slug' => 'admin-map-institute-development-test',
            'city_id' => $city->id,
            'recommended_level_min' => 45,
            'recommended_level_max' => 55,
        ]);
        $enemy = $this->createExplorationMapEnemyFixtures($area, '地図院発展値試験魔物', 50)['normal'];
        $owner = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '地図院発展値試験発見者',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 50000,
        ]);
        $payer = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '地図院発展値試験利用者',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 50000,
        ]);
        $map = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000103');
        $registration = app(MapSurveyService::class)->start($owner, $map, $city);
        $contributedAt = now()->subHour()->startOfMinute();
        $batch = MapExplorationBatch::create([
            'uuid' => (string) Str::uuid(),
            'request_uuid' => (string) Str::uuid(),
            'registration_id' => $registration->id,
            'map_id' => $map->id,
            'character_id' => $payer->id,
            'requested_count' => 1,
            'reserved_count' => 1,
            'executed_count' => 1,
            'first_exploration_index' => 1,
            'last_exploration_index' => 1,
            'fee_per_exploration' => 1000,
            'total_fee' => 1000,
            'status' => 'completed',
            'completed_at' => $contributedAt,
        ]);
        MapIncomeLog::create([
            'batch_id' => $batch->id,
            'map_id' => $map->id,
            'registration_id' => $registration->id,
            'payer_character_id' => $payer->id,
            'owner_character_id' => $owner->id,
            'executed_count' => 1,
            'total_entry_fee' => 1000,
            'owner_share' => 700,
            'town_share' => 200,
            'system_share' => 100,
            'created_at' => $contributedAt,
            'updated_at' => $contributedAt,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.published-maps'))
            ->assertOk()
            ->assertSee('地図院発展値（累計）')
            ->assertSee('全地図院合計')
            ->assertSee($city->name)
            ->assertSee('3,200')
            ->assertSee('積立 1回')
            ->assertSee($contributedAt->format('Y/m/d H:i'));

        $this->assertSame(3200, (int) City::findOrFail($city->id)->map_institute_development);
        $this->assertSame(1, MapIncomeLog::count());
    }
}
