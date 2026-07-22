<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_map_keeps_its_seeded_payload_and_limit_range(): void
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '試験地', 'slug' => 'map-test', 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => '試験魔物', 'area_id' => $area->id, 'level' => 45, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図師', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $map = app(ExplorationMapGenerator::class)->generate($character, $area, $enemy, '00000000-0000-4000-8000-000000000001');

        $this->assertSame('uninvestigated', $map->status);
        $this->assertGreaterThanOrEqual(600, $map->exploration_limit);
        $this->assertLessThanOrEqual(2000, $map->exploration_limit);
        $this->assertNotEmpty($map->normal_monster_variants_json);
        $this->assertSame(64, strlen($map->seed_hash));
        $levelRange = config('exploration_maps.grade_level_offsets.' . $map->map_grade);
        $firstVariant = $map->normal_monster_variants_json[0];
        $baseMonster = Enemy::findOrFail($firstVariant['base_monster_id']);
        $this->assertGreaterThanOrEqual((int) $baseMonster->level + (int) $levelRange['min'], (int) $firstVariant['enemy_level']);
        $this->assertLessThanOrEqual((int) $baseMonster->level + (int) $levelRange['max'], (int) $firstVariant['enemy_level']);
        $mapDetails = app(\App\Services\ExplorationMapDisplayService::class)->details($map);
        $this->assertNotSame('不明', $mapDetails['enemy_power_range']);
        $this->assertGreaterThan(0, $mapDetails['enemy_power_min']);
        $this->assertNotSame('', $mapDetails['reward']);

        $surveyService = app(MapSurveyService::class);
        $expectedSurveyCost = $surveyService->cost();
        $this->assertSame(5000, $expectedSurveyCost);
        $registration = $surveyService->start($character, $map, $city);
        $this->assertSame('completed', $registration->survey_status);
        $this->assertSame($expectedSurveyCost, $registration->survey_cost);
        $this->assertSame('surveyed', $map->fresh()->status);
        $publicationService = app(MapPublicationService::class);
        $feeOptions = $publicationService->feeOptions($registration);
        $this->assertCount(6, $feeOptions);
        $this->assertSame(0, $feeOptions[0]['fee']);
        $this->assertContains($publicationService->recommendedFee($registration), array_column($feeOptions, 'fee'));
        $entryFee = (int) $feeOptions[1]['fee'];
        $published = $publicationService->publish($character, $registration, $entryFee);
        $this->assertTrue($published->isOpen());
        $this->assertSame((int) $published->exploration_limit, (int) $published->remaining_explorations);
        $this->assertEquals(72, $published->published_at->diffInHours($published->expires_at));
        $this->assertSame(1, TownMapRegistration::where('map_id', $map->id)->count());

        $visitor = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図探索者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $batch = app(\App\Services\MapExplorationBatchService::class)->reserve($visitor, $published, 10, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(10, (int) $batch->reserved_count);
        $this->assertSame($entryFee, (int) $batch->total_fee);
        $this->assertSame(10000 - $entryFee, (int) $visitor->fresh()->money);

        $continued = app(\App\Services\MapExplorationBatchService::class)->reserve($visitor, $published, 10, (string) \Illuminate\Support\Str::uuid(), false);
        $this->assertSame(0, (int) $continued->total_fee);
        $this->assertSame(10000 - $entryFee, (int) $visitor->fresh()->money);

        $reentered = app(\App\Services\MapExplorationBatchService::class)->reserve($visitor, $published, 10, (string) \Illuminate\Support\Str::uuid());
        $this->assertSame($entryFee, (int) $reentered->total_fee);
        $this->assertSame(10000 - ($entryFee * 2), (int) $visitor->fresh()->money);

        $published->update(['remaining_explorations' => 0, 'expires_at' => now()->subMinute()]);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($visitor->user)
            ->withSession(['current_character_id' => $visitor->id])
            ->get(route('exploration-maps.published'))
            ->assertOk()
            ->assertSee($map->name)
            ->assertSee('公開終了');
    }

    public function test_generated_map_selects_each_city_theme_evenly_and_keeps_enemy_levels_in_the_middle_to_upper_band(): void
    {
        $weights = config('exploration_maps.target_city_weights');
        $this->assertSame(array_fill(1, 10, 1), $weights);
        $this->assertSame(['min' => 45, 'max' => 140], config('exploration_maps.target_enemy_level_range'));
        $this->assertSame('images/chizu/map-bg-lava-cave.webp', config('exploration_maps.dungeon_card_backgrounds.mine_volcano'));
        $this->assertSame('images/chizu/map-bg-floating-sanctuary.webp', config('exploration_maps.dungeon_card_backgrounds.sky_ruins'));
        $this->assertGreaterThanOrEqual(10, count(config('exploration_maps.map_name_parts.magic.0')));
        $this->assertGreaterThanOrEqual(10, count(config('exploration_maps.map_name_parts.abyss.2')));

        $areas = [];
        foreach (range(1, 10) as $cityId) {
            $city = City::findOrFail($cityId);
            $area = Area::create(['name' => '試験地' . $cityId, 'slug' => 'map-target-' . $cityId, 'city_id' => $city->id, 'recommended_level_min' => $cityId * 10, 'recommended_level_max' => ($cityId * 10) + 9]);
            Enemy::create(['name' => '試験魔物' . $cityId, 'area_id' => $area->id, 'level' => $cityId * 10, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
            $areas[$cityId] = $area;
        }
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図師', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $originArea = $areas[3];
        $originMonster = Enemy::where('area_id', $originArea->id)->firstOrFail();
        $generated = collect(range(1, 24))->map(fn (int $index) => app(ExplorationMapGenerator::class)->generate($character, $originArea, $originMonster, sprintf('00000000-0000-4000-8000-%012d', $index)));

        $this->assertContains($originArea->id, $generated->pluck('generation_payload_json')->pluck('origin_area_id')->all());
        $this->assertTrue($generated->every(fn ($map) => $map->sourceArea?->city_id >= 1 && $map->sourceArea?->city_id <= 10));
        $this->assertGreaterThan(1, $generated->pluck('source_area_id')->unique()->count());
        $this->assertTrue($generated->every(fn ($map) => collect($map->normal_monster_variants_json)->every(fn ($variant) => (int) $variant['enemy_level'] >= 45 && (int) $variant['enemy_level'] <= 140)));
        $this->assertTrue($generated->every(fn ($map) => str_ends_with($map->name, 'の地図')));
    }

    public function test_recently_closed_registration_is_kept_for_six_hours(): void
    {
        $registration = new TownMapRegistration([
            'status' => 'published',
            'published_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
            'remaining_explorations' => 0,
        ]);
        $registration->updated_at = now()->subMinute();

        $this->assertFalse($registration->isOpen());
        $this->assertTrue($registration->isRecentlyClosed());

        $registration->updated_at = now()->subHours(7);
        $this->assertFalse($registration->isRecentlyClosed());
    }
}
