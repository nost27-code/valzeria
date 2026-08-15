<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MapExplorationBatch;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ExplorationMapDiscardService;
use App\Services\ExplorationMapGenerator;
use App\Services\MapExplorationItemService;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesExplorationMapEnemyFixtures;
use Tests\TestCase;

class ExplorationMapLifecycleTest extends TestCase
{
    use CreatesExplorationMapEnemyFixtures, RefreshDatabase;

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

    public function test_owner_can_bulk_discard_uninvestigated_and_surveyed_maps(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $uninvestigated = $this->generateMap($character, $area, $enemy, 22);
        $surveyed = $this->generateMap($character, $area, $enemy, 23);
        $registration = app(MapSurveyService::class)->start($character, $surveyed, $city);

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('exploration-maps.bulk-discard'), [
                'map_ids' => [$uninvestigated->id, $surveyed->id],
            ])
            ->assertRedirect(route('exploration-maps.index'))
            ->assertSessionHas('message', '選択した2件の探索地図を破棄した。');

        $this->assertSame('discarded', $uninvestigated->fresh()->status);
        $this->assertSame('discarded', $surveyed->fresh()->status);
        $this->assertSame('discarded', $registration->fresh()->status);
    }

    public function test_bulk_discard_rejects_the_whole_selection_when_it_contains_a_published_map(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $uninvestigated = $this->generateMap($character, $area, $enemy, 24);
        $published = $this->generateMap($character, $area, $enemy, 25);
        app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $published, $city),
            0,
        );

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('exploration-maps.index'))
            ->post(route('exploration-maps.bulk-discard'), [
                'map_ids' => [$uninvestigated->id, $published->id],
            ])
            ->assertRedirect(route('exploration-maps.index'))
            ->assertSessionHas('error', '公開中または処理中の地図は破棄できません。');

        $this->assertSame('uninvestigated', $uninvestigated->fresh()->status);
        $this->assertSame('published', $published->fresh()->status);
    }

    public function test_bulk_discard_cannot_include_another_characters_map(): void
    {
        [$character, , $area, $enemy] = $this->mapContext();
        $owned = $this->generateMap($character, $area, $enemy, 28);
        $otherCharacter = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '別の地図所有者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $otherMap = $this->generateMap($otherCharacter, $area, $enemy, 29);

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('exploration-maps.index'))
            ->post(route('exploration-maps.bulk-discard'), [
                'map_ids' => [$owned->id, $otherMap->id],
            ])
            ->assertRedirect(route('exploration-maps.index'))
            ->assertSessionHas('error', 'この地図は破棄できません。');

        $this->assertSame('uninvestigated', $owned->fresh()->status);
        $this->assertSame('uninvestigated', $otherMap->fresh()->status);
    }

    public function test_surveyed_map_summary_is_shown_only_after_survey(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $uninvestigated = $this->generateMap($character, $area, $enemy, 26);
        $surveyed = $this->generateMap($character, $area, $enemy, 27);
        $uninvestigated->update([
            'reward_profile' => 'experience',
            'reward_modifiers_json' => app(\App\Services\ExplorationMapRewardProfileService::class)
                ->modifiers('experience', (string) $uninvestigated->map_grade),
        ]);
        $surveyed->update([
            'reward_profile' => 'training',
            'reward_modifiers_json' => app(\App\Services\ExplorationMapRewardProfileService::class)
                ->modifiers('training', (string) $surveyed->map_grade),
        ]);
        app(MapSurveyService::class)->start($character, $surveyed->fresh(), $city);

        $response = $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('exploration-maps.index'));

        $response->assertOk()
            ->assertSee('報酬傾向：修練の導き')
            ->assertSee('目安戦力：')
            ->assertSee('未調査の探索地図')
            ->assertDontSee('経験の導き')
            ->assertSee('name="map_ids[]"', false);
        $this->assertSame('uninvestigated', $uninvestigated->fresh()->status);
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

    public function test_owner_can_open_published_map_detail_without_active_entry(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $map = $this->generateMap($character, $area, $enemy, 26);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('exploration-maps.show', $registration))
            ->assertOk()
            ->assertSee('発見者は無料')
            ->assertSee('この地図の公開を取り下げる');
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

    public function test_home_restores_an_active_map_entry_from_the_database(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $this->grantStarterValmon($character);
        $map = $this->generateMap($character, $area, $enemy, 35);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );
        app(MapExplorationItemService::class)->begin($character, $registration);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('home'))
            ->assertRedirect(route('exploration-maps.published'))
            ->assertSessionHas('message', '探索中の地図へ戻りました。')
            ->assertSessionHas('active_map_exploration.registration_id', $registration->id);

        $this->get(route('exploration-maps.published'))
            ->assertOk()
            ->assertSee('この地図を探索中です')
            ->assertSee('地図探索を切り上げる');
    }

    public function test_active_map_entry_blocks_inventory_mutations_until_returning(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $this->grantStarterValmon($character);
        $map = $this->generateMap($character, $area, $enemy, 36);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );
        app(MapExplorationItemService::class)->begin($character, $registration);
        $material = Material::query()->firstOrFail();
        $owned = CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => 3,
        ]);
        $moneyBefore = (int) $character->fresh()->money;

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('inventory.sell'), [
                'character_material_id' => $owned->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('exploration-maps.published'))
            ->assertSessionHas('error', '探索中の地図を切り上げてから行ってください。');

        $this->assertSame(3, (int) $owned->fresh()->quantity);
        $this->assertSame($moneyBefore, (int) $character->fresh()->money);

        $this->post(route('battle.explore', $area))
            ->assertRedirect(route('exploration-maps.published'))
            ->assertSessionHas('error', '探索中の地図を切り上げてから行ってください。');

        $this->assertTrue(app(MapExplorationItemService::class)->hasEntry($character, $registration->id));
    }

    public function test_active_map_entry_cannot_be_replaced_by_another_map(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $this->grantStarterValmon($character);
        $firstMap = $this->generateMap($character, $area, $enemy, 37);
        $secondMap = $this->generateMap($character, $area, $enemy, 38);
        $firstRegistration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $firstMap, $city),
            0,
        );
        $secondRegistration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $secondMap, $city),
            0,
        );
        app(MapExplorationItemService::class)->begin($character, $firstRegistration);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('exploration-maps.published'))
            ->post(route('exploration-maps.explore', $secondRegistration), [
                'count' => 1,
                'request_uuid' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('exploration-maps.published'))
            ->assertSessionHas('error', '別の地図を探索中です。現在の地図探索を切り上げてから入場してください。');

        $this->assertTrue(app(MapExplorationItemService::class)->hasEntry($character, $firstRegistration->id));
        $this->assertFalse(app(MapExplorationItemService::class)->hasEntry($character, $secondRegistration->id));
        $this->assertSame(0, MapExplorationBatch::query()->count());
    }

    public function test_active_map_entry_can_continue_through_the_battle_route(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $this->grantStarterValmon($character);
        $map = $this->generateMap($character, $area, $enemy, 39);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );
        app(MapExplorationItemService::class)->begin($character, $registration);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('battle.explore', $area), [
                'continue_chain' => true,
                'batch_count' => 1,
            ])
            ->assertRedirect(route('battle.result'));

        $this->assertTrue(app(MapExplorationItemService::class)->hasEntry($character, $registration->id));
    }

    public function test_invalid_map_seed_does_not_consume_exploration_count(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $map = $this->generateMap($character, $area, $enemy, 41);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );
        $map->update(['seed_encrypted' => 'invalid-encrypted-seed']);
        $remainingBefore = (int) $registration->remaining_explorations;

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('exploration-maps.explore', $registration), [
                'count' => 10,
                'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'この地図の探索情報を読み込めませんでした。探索回数・料金・探索力は消費されていません。別の地図を選んでください。');

        $this->assertSame($remainingBefore, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(0, (int) $registration->fresh()->consumed_explorations);
        $this->assertSame(0, MapExplorationBatch::count());
    }

    public function test_invalid_map_seed_during_active_map_continuation_rolls_back_reservation(): void
    {
        [$character, $city, $area, $enemy] = $this->mapContext();
        $this->grantStarterValmon($character);
        $map = $this->generateMap($character, $area, $enemy, 43);
        $registration = app(MapPublicationService::class)->publish(
            $character,
            app(MapSurveyService::class)->start($character, $map, $city),
            0,
        );
        app(MapExplorationItemService::class)->begin($character, $registration);
        $map->update(['seed_encrypted' => 'invalid-encrypted-seed']);
        $remainingBefore = (int) $registration->remaining_explorations;

        $this->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                'active_map_exploration' => [
                    'registration_id' => $registration->id,
                    'area_id' => $area->id,
                ],
            ])
            ->post(route('battle.explore', $area), [
                'continue_chain' => true,
                'batch_count' => 10,
            ])
            ->assertRedirect(route('battle.result'))
            ->assertSessionHas('error');

        $this->assertSame($remainingBefore, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(0, (int) $registration->fresh()->consumed_explorations);
        $this->assertSame(0, MapExplorationBatch::count());
    }

    /** @return array{Character, City, Area, Enemy} */
    private function mapContext(): array
    {
        config()->set('exploration_maps.reward_profiles.ancient_fragment.weight', 0);
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '地図運用試験地', 'slug' => 'map-lifecycle-test', 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = $this->createExplorationMapEnemyFixtures($area, '地図運用試験魔物')['normal'];
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図運用者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 100000]);

        return [$character, $city, $area, $enemy];
    }

    private function generateMap(Character $character, Area $area, Enemy $enemy, int $sequence): ExplorationMap
    {
        return app(ExplorationMapGenerator::class)->generate($character, $area, $enemy, sprintf('00000000-0000-4000-8000-%012d', $sequence));
    }

    private function grantStarterValmon(Character $character): void
    {
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'map-lifecycle-' . $character->id,
            'name' => '地図試験ヴァルモン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);
    }
}
