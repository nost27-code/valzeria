<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationMapDiscardService;
use App\Services\ExplorationMapGenerator;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_publish_more_than_three_open_maps(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();

        foreach (range(1, 3) as $index) {
            $map = $this->generateMap($character, $area, $enemy, $index);
            TownMapRegistration::create([
                'map_id' => $map->id,
                'town_id' => $city->id,
                'survey_status' => 'completed',
                'exploration_limit' => $map->exploration_limit,
                'remaining_explorations' => $map->exploration_limit,
                'published_at' => now(),
                'expires_at' => now()->addHour(),
                'status' => 'published',
            ]);
            $map->update(['status' => 'published']);
        }

        $target = $this->generateMap($character, $area, $enemy, 4);
        $target->update(['map_grade' => 'normal']);
        $registration = app(MapSurveyService::class)->start($character, $target->fresh(), $city);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('公開中の地図は3件までです。');
        app(MapPublicationService::class)->publish($character, $registration, 0);
    }

    public function test_expired_and_exhausted_maps_do_not_count_toward_publication_limit(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();

        foreach ([now()->addHour(), now()->subMinute(), now()->addHour()] as $index => $expiresAt) {
            $map = $this->generateMap($character, $area, $enemy, $index + 11);
            TownMapRegistration::create([
                'map_id' => $map->id,
                'town_id' => $city->id,
                'survey_status' => 'completed',
                'exploration_limit' => $map->exploration_limit,
                'remaining_explorations' => $index === 2 ? 0 : $map->exploration_limit,
                'published_at' => now()->subHour(),
                'expires_at' => $expiresAt,
                'status' => 'published',
            ]);
            $map->update(['status' => 'published']);
        }

        $target = $this->generateMap($character, $area, $enemy, 14);
        $target->update(['map_grade' => 'normal']);
        $registration = app(MapSurveyService::class)->start($character, $target->fresh(), $city);

        $published = app(MapPublicationService::class)->publish($character, $registration, 0);

        $this->assertTrue($published->isOpen());
    }

    public function test_owner_can_discard_surveyed_map_without_deleting_history(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $map = $this->generateMap($character, $area, $enemy, 21);
        $registration = app(MapSurveyService::class)->start($character, $map, $city);

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('exploration-maps.discard', $map))
            ->assertRedirect(route('exploration-maps.index'));

        $this->assertSame('discarded', $map->fresh()->status);
        $this->assertSame('discarded', $registration->fresh()->status);
        $this->assertSame('discarded', $registration->fresh()->survey_status);
    }

    public function test_surveyed_map_is_shown_as_waiting_for_publication(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $map = $this->generateMap($character, $area, $enemy, 25);
        $registration = app(MapSurveyService::class)->start($character, $map, $city);

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('exploration-maps.show', $registration))
            ->assertOk()
            ->assertSee('状態：調査完了（公開待ち）')
            ->assertDontSee('状態：終了');
    }

    public function test_published_map_cannot_be_discarded(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $map = $this->generateMap($character, $area, $enemy, 31);
        $registration = app(MapSurveyService::class)->start($character, $map, $city);
        app(MapPublicationService::class)->publish($character, $registration, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('公開中または処理中の地図は破棄できません。');
        app(ExplorationMapDiscardService::class)->discard($character, $map);
    }

    /** @return array{Character, City, Area, Enemy} */
    private function mapContext(): array
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '地図運用試験地', 'slug' => 'map-lifecycle-test', 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => '地図運用試験魔物', 'area_id' => $area->id, 'level' => 45, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図運用者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 100000]);

        return [$character, $city, $area, $enemy];
    }

    private function generateMap(Character $character, Area $area, Enemy $enemy, int $sequence): ExplorationMap
    {
        return app(ExplorationMapGenerator::class)->generate($character, $area, $enemy, sprintf('00000000-0000-4000-8000-%012d', $sequence));
    }
}
