<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\CityHeader;
use App\Models\Character;
use App\Models\SixHeroChampion;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class SixHeroProfileAchievementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tokyo',
            'features.six_hero_ui_enabled' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_existing_adventurer_card_shows_current_rankings_without_confirmed_achievement_summary(): void
    {
        $viewer = $this->character('プロフィール閲覧者');
        $target = $this->character('プロフィール対象英雄');
        $other = $this->character('混入禁止の別英雄');
        $may = $this->season('2026-05');
        $june = $this->season('2026-06');
        $july = $this->season('2026-07');
        $august = $this->season('2026-08');

        $this->champions($may, [
            SixHeroRoomKey::DIVINE_SPEED->value => $target,
        ]);
        $this->champions($june, [
            SixHeroRoomKey::DIVINE_SPEED->value => $target,
            SixHeroRoomKey::MIRACLE->value => $target,
        ]);
        $this->champions($july, array_fill_keys(
            array_map(
                fn (SixHeroRoomKey $room): string => $room->value,
                SixHeroRoomKey::cases(),
            ),
            $other,
        ));
        $this->ranking(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            $target,
            rank: 1,
            challengeWins: 7,
            challengeLosses: 2,
            defenseWins: 4,
            defenseLosses: 1,
        );
        $this->ranking(
            $august,
            SixHeroRoomKey::MIRACLE,
            $target,
            rank: 4,
            challengeWins: 3,
            challengeLosses: 1,
            defenseWins: 2,
            defenseLosses: 2,
        );
        $before = $this->competitionSnapshot();

        $this->actingAs($viewer->user)
            ->withSession(['current_character_id' => $viewer->id]);

        $component = Livewire::test(CityHeader::class, [
            'modalOnly' => true,
            'showPreviewButton' => false,
        ])
            ->assertDontSee('プレビュー')
            ->call('openPlayerModal', $target->id)
            ->assertSet('isPlayerModalOpen', true)
            ->assertSet('playerInfo.id', $target->id)
            ->assertSet('playerInfo.six_hero_current_record.currentCrownCount', 1)
            ->assertSet('playerInfo.six_hero_current_record.rooms.0.rank', 1)
            ->assertSet('playerInfo.six_hero_current_record.rooms.0.challengeWins', 7)
            ->assertSet('playerInfo.six_hero_current_record.rooms.1.rank', 4)
            ->assertSet('playerInfo.six_hero_current_record.rooms.1.defenseWins', 2)
            ->assertSee('今期の六英雄戦績')
            ->assertDontSee('今期の六極殿戦績')
            ->assertDontSee('挑戦')
            ->assertDontSee('防衛')
            ->assertSee('六英雄戦績')
            ->assertDontSee('確定済みの六英雄実績')
            ->assertDontSee('英雄獲得')
            ->assertDontSee('まだ六英雄の記録はありません。')
            ->assertSee('六極殿で詳しく見る')
            ->assertSeeHtml('data-profile-six-hero-section')
            ->assertSeeHtml('data-profile-six-hero-current-record')
            ->assertSeeHtml('data-profile-six-hero-room-grid')
            ->assertDontSeeHtml('data-profile-six-hero-achievement');

        $this->assertArrayNotHasKey('six_hero_achievement', $component->get('playerInfo'));
        $this->assertSame($before, $this->competitionSnapshot());

        config(['features.six_hero_ui_enabled' => false]);

        $disabledComponent = Livewire::test(CityHeader::class, ['modalOnly' => true])
            ->call('openPlayerModal', $target->id)
            ->assertSet('playerInfo.six_hero_current_record', null)
            ->assertDontSeeHtml('data-profile-six-hero-section')
            ->assertDontSee('今期の六英雄戦績')
            ->assertDontSeeHtml('data-profile-six-hero-current-record')
            ->assertDontSee('確定済みの六英雄実績')
            ->assertDontSeeHtml('data-profile-six-hero-achievement');

        $this->assertArrayNotHasKey('six_hero_achievement', $disabledComponent->get('playerInfo'));

        $profileView = file_get_contents(resource_path('views/livewire/city-header.blade.php'));
        $sixHeroSectionPosition = strpos($profileView, 'data-profile-six-hero-section');
        $currentRecordPosition = strpos($profileView, 'data-profile-six-hero-current-record');
        $detailsLinkPosition = strpos($profileView, '六極殿で詳しく見る');
        $favoriteWeaponsPosition = strpos($profileView, 'playerInfo.favorite_weapons_enabled');
        $this->assertIsInt($sixHeroSectionPosition);
        $this->assertIsInt($currentRecordPosition);
        $this->assertIsInt($detailsLinkPosition);
        $this->assertIsInt($favoriteWeaponsPosition);
        $this->assertLessThan($favoriteWeaponsPosition, $sixHeroSectionPosition);
        $this->assertLessThan($detailsLinkPosition, $currentRecordPosition);

        $currentRecordView = substr($profileView, $currentRecordPosition, $detailsLinkPosition - $currentRecordPosition);
        $this->assertStringContainsString('grid-cols-3', $currentRecordView);
        $this->assertStringContainsString(':data-rank-band="room.rankTone.band"', $currentRecordView);
        $this->assertStringContainsString('room.rankTone.background', $currentRecordView);
        $this->assertStringContainsString('room.rankTone.border', $currentRecordView);
        $this->assertStringContainsString('room.rankTone.text', $currentRecordView);
        $this->assertStringNotContainsString('room.challengeWins', $currentRecordView);
        $this->assertStringNotContainsString('room.challengeLosses', $currentRecordView);
        $this->assertStringNotContainsString('room.defenseWins', $currentRecordView);
        $this->assertStringNotContainsString('room.defenseLosses', $currentRecordView);
    }

    public function test_current_room_rankings_use_six_subtle_color_bands(): void
    {
        $viewer = $this->character('順位色閲覧者');
        $target = $this->character('順位色対象者');
        $season = $this->season('2026-08');
        $ranks = [1, 2, 4, 7, 11, 21];
        $expectedBands = ['first', 'top-three', 'top-six', 'top-ten', 'top-twenty', 'standard'];

        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $this->ranking(
                $season,
                $room,
                $target,
                rank: $ranks[$index],
                challengeWins: 0,
                challengeLosses: 0,
                defenseWins: 0,
                defenseLosses: 0,
            );
        }

        $this->actingAs($viewer->user)
            ->withSession(['current_character_id' => $viewer->id]);

        $component = Livewire::test(CityHeader::class, ['modalOnly' => true])
            ->call('openPlayerModal', $target->id);

        foreach ($expectedBands as $index => $band) {
            $component
                ->assertSet("playerInfo.six_hero_current_record.rooms.{$index}.rank", $ranks[$index])
                ->assertSet("playerInfo.six_hero_current_record.rooms.{$index}.rankTone.band", $band);
        }

        $rooms = $component->get('playerInfo')['six_hero_current_record']['rooms'];
        foreach ($rooms as $room) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $room['rankTone']['background']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $room['rankTone']['border']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $room['rankTone']['text']);
        }
    }

    public function test_online_players_mark_only_current_room_leaders_as_top_rankers(): void
    {
        Cache::flush();
        $viewer = $this->character('現在の冒険者閲覧者');
        $leader = $this->character('六極首位プレイヤー');
        $ordinary = $this->character('一般プレイヤー');
        $leader->forceFill(['last_seen_at' => now()])->saveQuietly();
        $ordinary->forceFill(['last_seen_at' => now()])->saveQuietly();
        $season = $this->season('2026-08');
        $this->ranking(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $leader,
            rank: 1,
            challengeWins: 0,
            challengeLosses: 0,
            defenseWins: 0,
            defenseLosses: 0,
        );
        $this->ranking(
            $season,
            SixHeroRoomKey::MIRACLE,
            $leader,
            rank: 1,
            challengeWins: 0,
            challengeLosses: 0,
            defenseWins: 0,
            defenseLosses: 0,
        );
        $this->ranking(
            $season,
            SixHeroRoomKey::MIRACLE,
            $ordinary,
            rank: 2,
            challengeWins: 0,
            challengeLosses: 0,
            defenseWins: 0,
            defenseLosses: 0,
        );

        $this->actingAs($viewer->user)
            ->withSession(['current_character_id' => $viewer->id]);

        Livewire::test(CityHeader::class)
            ->assertSeeHtml('data-six-hero-top-ranker="'.$leader->id.'"')
            ->assertSeeHtml('aria-label="六英雄戦 現在首位（神速の間・奇跡の間） '.$leader->name.'"')
            ->assertSeeHtml('data-six-hero-crown="divine_speed"')
            ->assertSeeHtml('crown_004.webp')
            ->assertSeeHtml('data-six-hero-crown="miracle"')
            ->assertSeeHtml('crown_006.webp')
            ->assertDontSeeHtml('data-six-hero-top-ranker="'.$ordinary->id.'"');

        config(['features.six_hero_ui_enabled' => false]);

        Livewire::test(CityHeader::class)
            ->assertDontSeeHtml('data-six-hero-top-ranker')
            ->assertDontSeeHtml('data-six-hero-crown');
    }

    private function ranking(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
        int $rank,
        int $challengeWins,
        int $challengeLosses,
        int $defenseWins,
        int $defenseLosses,
    ): SixHeroRanking {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $character->id,
            'rank' => $rank,
            'official_attack_wins' => $challengeWins,
            'official_attack_losses' => $challengeLosses,
            'defense_wins' => $defenseWins,
            'defense_losses' => $defenseLosses,
            'registered_at' => $season->starts_at,
            'first_place_since' => $rank === 1 ? $season->starts_at : null,
        ]);
    }

    private function season(string $key): SixHeroSeason
    {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo')->startOfMonth();
        $endsAt = $startsAt->copy()->addMonth();

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $endsAt,
            'ranking_initialized_at' => $startsAt,
        ]);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
    }

    /** @param array<string, Character> $heroes */
    private function champions(SixHeroSeason $season, array $heroes): void
    {
        foreach (SixHeroRoomKey::cases() as $room) {
            $hero = $heroes[$room->value] ?? null;

            SixHeroChampion::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'character_id' => $hero?->id,
                'character_id_snapshot' => $hero?->id,
                'character_name_snapshot' => $hero?->name,
                'is_vacant' => $hero === null,
                'vacancy_reason' => $hero === null
                    ? SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY
                    : null,
                'registered_count' => $hero === null ? 0 : 12,
                'official_battle_count' => $hero === null ? 0 : 20,
                'official_attack_wins' => $hero === null ? null : 8,
                'official_attack_losses' => $hero === null ? null : 2,
                'defense_wins' => $hero === null ? null : 3,
                'defense_losses' => $hero === null ? null : 1,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function competitionSnapshot(): array
    {
        return [
            'rankings' => DB::table('six_hero_rankings')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
            'dailyUsages' => DB::table('six_hero_daily_usages')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
            'battleLogs' => DB::table('six_hero_battle_logs')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
            'champions' => DB::table('six_hero_champions')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
