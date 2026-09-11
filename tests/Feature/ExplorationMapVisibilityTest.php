<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\MapExplorationBatch;
use App\Models\NationMembership;
use App\Models\PublicLog;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationMapGenerator;
use App\Services\MapExplorationBatchService;
use App\Services\MapExplorationItemService;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use App\Services\Nation\NationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesExplorationMapEnemyFixtures;
use Tests\TestCase;

class ExplorationMapVisibilityTest extends TestCase
{
    use CreatesExplorationMapEnemyFixtures, RefreshDatabase;

    public function test_owner_only_map_is_hidden_from_other_characters_and_forces_free_entry(): void
    {
        [$owner, $city, $area, $enemy] = $this->mapContext();
        $registration = $this->surveyedRegistration($owner, $city, $area, $enemy, 1, 'hero');
        $maximumFee = app(MapPublicationService::class)->maxFee($registration);
        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($owner->user)
            ->withSession(['current_character_id' => $owner->id])
            ->get(route('exploration-maps.show', $registration))
            ->assertOk()
            ->assertSee('自分だけ')
            ->assertSee('国家限定')
            ->assertSee('すべての冒険者');
        $this->post(route('exploration-maps.publish', $registration), [
            'entry_fee' => $maximumFee,
            'visibility_scope' => TownMapRegistration::VISIBILITY_OWNER,
        ])->assertRedirect(route('exploration-maps.show', $registration))
            ->assertSessionHas('message', '公開範囲を「自分だけ」にして地図を公開した。');
        $published = $registration->fresh(['map', 'publicationNation']);

        $this->assertSame(TownMapRegistration::VISIBILITY_OWNER, $published->visibility_scope);
        $this->assertNull($published->nation_id_snapshot);
        $this->assertSame(0, (int) $published->entry_fee_per_exploration);
        $this->assertSame(0, PublicLog::where('type', 'system_map_published')->count());

        $outsider = $this->character('公開範囲外の冒険者');
        $remainingBefore = (int) $published->remaining_explorations;
        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($outsider->user)
            ->withSession(['current_character_id' => $outsider->id])
            ->get(route('exploration-maps.published'))
            ->assertOk()
            ->assertDontSee($published->map->name);

        $this->get(route('exploration-maps.show', $published))->assertNotFound();
        $this->from(route('exploration-maps.published'))
            ->post(route('exploration-maps.explore', $published), [
                'count' => 1,
                'request_uuid' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('exploration-maps.published'))
            ->assertSessionHas('error', 'この地図の公開範囲には入っていません。');

        $this->assertSame($remainingBefore, (int) $published->fresh()->remaining_explorations);
        $this->assertSame(0, MapExplorationBatch::count());
    }

    public function test_nation_map_is_visible_only_to_the_publication_nation_and_owner(): void
    {
        config()->set('features.nation_community_enabled', true);
        [$owner, $city, $area, $enemy] = $this->mapContext();
        $nation = app(NationService::class)->create($owner, '地図共有');
        $member = $this->character('同じ国家の冒険者');
        NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $member->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $outsider = $this->character('無所属の冒険者');
        $otherNationOwner = $this->character('別国家の冒険者');
        app(NationService::class)->create($otherNationOwner, '別地図共有');
        $registration = $this->surveyedRegistration($owner, $city, $area, $enemy, 2, 'hero');
        $published = app(MapPublicationService::class)->publish(
            $owner,
            $registration,
            0,
            TownMapRegistration::VISIBILITY_NATION,
        );

        $this->assertSame($nation->id, (int) $published->nation_id_snapshot);
        $this->assertSame(0, PublicLog::where('type', 'system_map_published')->count());

        foreach ([$owner, $member] as $viewer) {
            $this->withoutMiddleware(CheckCharacterSelected::class)
                ->actingAs($viewer->user)
                ->withSession(['current_character_id' => $viewer->id])
                ->get(route('exploration-maps.published'))
                ->assertOk()
                ->assertSee($published->map->name);
        }

        foreach ([$outsider, $otherNationOwner] as $viewer) {
            $this->actingAs($viewer->user)
                ->withSession(['current_character_id' => $viewer->id])
                ->get(route('exploration-maps.published'))
                ->assertOk()
                ->assertDontSee($published->map->name);
            $this->get(route('exploration-maps.show', $published))->assertNotFound();
        }
    }

    public function test_nation_scope_requires_current_membership(): void
    {
        config()->set('features.nation_community_enabled', true);
        [$owner, $city, $area, $enemy] = $this->mapContext();
        $registration = $this->surveyedRegistration($owner, $city, $area, $enemy, 3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('国家に所属している冒険者だけが、国家限定で公開できます。');

        app(MapPublicationService::class)->publish(
            $owner,
            $registration,
            0,
            TownMapRegistration::VISIBILITY_NATION,
        );
    }

    public function test_member_who_already_entered_can_continue_after_leaving_the_nation(): void
    {
        config()->set('features.nation_community_enabled', true);
        [$owner, $city, $area, $enemy] = $this->mapContext();
        $nation = app(NationService::class)->create($owner, '継続地図');
        $member = $this->character('継続する冒険者');
        $membership = NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $member->id,
            'role' => 'citizen',
            'joined_at' => now()->subDays(2),
        ]);
        $registration = app(MapPublicationService::class)->publish(
            $owner,
            $this->surveyedRegistration($owner, $city, $area, $enemy, 4),
            0,
            TownMapRegistration::VISIBILITY_NATION,
        );
        app(MapExplorationItemService::class)->begin($member, $registration);
        $this->assertTrue(app(MapExplorationItemService::class)->hasEntry($member, $registration->id));
        $membership->delete();

        $batch = app(MapExplorationBatchService::class)->reserve(
            $member,
            $registration,
            1,
            (string) Str::uuid(),
            false,
        );

        $this->assertSame($member->id, (int) $batch->character_id);
        $this->assertSame(0, (int) $batch->total_fee);
    }

    public function test_existing_publication_default_remains_visible_to_everyone(): void
    {
        [$owner, $city, $area, $enemy] = $this->mapContext();
        $published = app(MapPublicationService::class)->publish(
            $owner,
            $this->surveyedRegistration($owner, $city, $area, $enemy, 5),
            0,
        );
        $outsider = $this->character('従来公開の閲覧者');

        $this->assertSame(TownMapRegistration::VISIBILITY_ALL, $published->visibility_scope);
        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($outsider->user)
            ->withSession(['current_character_id' => $outsider->id])
            ->get(route('exploration-maps.published'))
            ->assertOk()
            ->assertSee($published->map->name);
    }

    /** @return array{Character, City, Area, Enemy} */
    private function mapContext(): array
    {
        config()->set('exploration_maps.reward_profiles.ancient_fragment.weight', 0);
        $city = City::findOrFail(1);
        $area = Area::create([
            'name' => '公開範囲試験地',
            'slug' => 'map-visibility-test',
            'city_id' => $city->id,
            'recommended_level_min' => 20,
            'recommended_level_max' => 30,
        ]);
        $enemy = $this->createExplorationMapEnemyFixtures($area, '公開範囲試験魔物')['normal'];

        return [$this->character('地図公開者'), $city, $area, $enemy];
    }

    private function character(string $name): Character
    {
        return Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 100000,
        ]);
    }

    private function surveyedRegistration(
        Character $owner,
        City $city,
        Area $area,
        Enemy $enemy,
        int $sequence,
        string $grade = 'normal',
    ): TownMapRegistration {
        $map = app(ExplorationMapGenerator::class)->generate(
            $owner,
            $area,
            $enemy,
            sprintf('00000000-0000-4000-8001-%012d', $sequence),
        );
        $map->update(['map_grade' => $grade]);

        return app(MapSurveyService::class)->start($owner, $map->fresh(), $city);
    }
}
