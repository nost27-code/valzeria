<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPublishedMapManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_review_only_currently_open_published_maps(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create([
            'name' => '管理公開地図試験地',
            'slug' => 'admin-published-map-test',
            'city_id' => $city->id,
            'recommended_level_min' => 45,
            'recommended_level_max' => 55,
        ]);
        $enemy = Enemy::create([
            'name' => '管理確認魔物',
            'area_id' => $area->id,
            'level' => 50,
            'max_hp' => 200,
            'str' => 40,
            'def' => 20,
            'agi' => 20,
            'mag' => 20,
            'spr' => 20,
            'luk' => 20,
            'exp_reward' => 50,
            'gold_reward' => 30,
            'job_exp_reward' => 1,
            'appearance_weight' => 1,
            'is_boss' => false,
        ]);
        $owner = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '公開地図の発見者',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 10000,
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
}
