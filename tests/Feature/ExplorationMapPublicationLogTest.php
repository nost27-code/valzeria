<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\MapPublicationLog;
use App\Models\PublicLog;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapPublicationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_hero_and_legend_maps_are_announced_in_public_chat(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '公開ログ試験地', 'slug' => 'map-public-log-test', 'city_id' => $city->id, 'recommended_level_min' => 45, 'recommended_level_max' => 55]);
        $enemy = Enemy::create(['name' => '公開ログ試験魔物', 'area_id' => $area->id, 'level' => 50, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $owner = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図公開者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $registration = new TownMapRegistration(['town_id' => $city->id]);
        $registration->setRelation('town', $city);
        $service = app(PublicLogService::class);

        $normal = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000901');
        $normal->update(['map_grade' => 'normal']);
        $service->addMapPublishedLog($normal->fresh(), $registration);

        $this->assertSame(0, PublicLog::where('type', 'system_map_published')->count());
        $this->assertSame(0, MapPublicationLog::where('map_id', $normal->id)->count());

        $hero = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000902');
        $hero->update(['map_grade' => 'hero']);
        $service->addMapPublishedLog($hero->fresh(), $registration);

        $this->assertSame(1, PublicLog::where('type', 'system_map_published')->count());
        $this->assertSame(1, MapPublicationLog::where('map_id', $hero->id)->count());
        $this->assertStringContainsString('【英雄地図】', (string) PublicLog::where('type', 'system_map_published')->value('message'));

        $legend = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000903');
        $legend->update(['map_grade' => 'legend']);
        $service->addMapPublishedLog($legend->fresh(), $registration);

        $this->assertSame(2, PublicLog::where('type', 'system_map_published')->count());
        $this->assertStringContainsString('【伝説地図】', (string) PublicLog::where('type', 'system_map_published')->latest('id')->value('message'));
    }
}
