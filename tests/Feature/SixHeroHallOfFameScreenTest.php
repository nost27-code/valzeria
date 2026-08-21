<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\SixHeroHallScreen;
use App\Models\Character;
use App\Models\SixHeroChampion;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class SixHeroHallOfFameScreenTest extends TestCase
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

    public function test_hall_reuses_the_app_layout_adventurer_card_host_without_mounting_a_duplicate(): void
    {
        $hallSource = file_get_contents(resource_path('views/livewire/six-hero-hall-screen.blade.php'));
        $layoutSource = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertIsString($hallSource);
        $this->assertIsString($layoutSource);
        $this->assertStringNotContainsString('data-six-hero-profile-modal-host', $hallSource);
        $this->assertStringNotContainsString('<livewire:city-header', $hallSource);
        $this->assertSame(1, substr_count($layoutSource, '<livewire:city-header'));
    }

    public function test_exact_previous_month_shows_all_six_finalized_snapshots_and_live_profile_links_only(): void
    {
        $current = $this->season('2026-08', finalized: false, initialized: true);
        $previous = $this->season('2026-07');
        $viewer = $this->character('閲覧者');
        $currentLeader = $this->character('現在首位A');
        $liveHero = $this->character('現在名へ改名済み');
        $otherHero = $this->character('前月神速英雄B');
        $deletedHero = $this->character('削除予定英雄');
        $deletedHeroId = (int) $deletedHero->id;

        $this->ranking($current, SixHeroRoomKey::DIVINE_SPEED, $currentLeader, 1);
        $this->champions($previous, [
            SixHeroRoomKey::SEAL_MAGIC->value => [$liveHero, '確定時の封魔英雄'],
            SixHeroRoomKey::BURNING_LIFE->value => $otherHero,
            SixHeroRoomKey::DIVINE_SPEED->value => $otherHero,
            SixHeroRoomKey::MIRACLE->value => [$deletedHero, '削除後も残る奇跡英雄'],
        ], [
            SixHeroRoomKey::SEAL_BLADE->value => [
                SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
                7,
                18,
            ],
            SixHeroRoomKey::REVERSE_TIME->value => [
                SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY,
                15,
                7,
            ],
        ]);
        $deletedHero->delete();

        $before = $this->competitionSnapshot();
        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->assertSee('前月の六英雄')
            ->assertSee('2026年7月期')
            ->assertSee('確定済')
            ->assertSee('現在首位A')
            ->assertSee('前月神速英雄B')
            ->assertSee('確定時の封魔英雄')
            ->assertDontSee('現在名へ改名済み')
            ->assertSee('削除後も残る奇跡英雄')
            ->assertSee('参加者数が条件未達')
            ->assertSee('公式戦数が条件未達')
            ->assertSee('登録者 7人')
            ->assertSee('公式戦 18戦')
            ->assertDontSee(SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS)
            ->assertDontSee(SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY)
            ->assertDontSee('character_id_snapshot')
            ->assertSeeHtml('data-hero-profile-character-id="'.$liveHero->id.'"')
            ->assertDontSeeHtml('data-hero-profile-character-id="'.$deletedHeroId.'"');

        $html = $component->html();
        $this->assertSame(6, substr_count($html, 'data-previous-six-hero-room='));
        $this->assertSame(3, substr_count($html, 'data-previous-six-hero-icon='));
        $this->assertSame(1, substr_count($html, 'data-previous-six-hero-icon-placeholder'));
        $this->assertStringContainsString('data-previous-six-heroes-summary', $html);
        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*data-previous-six-heroes)[^>]*>/u',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<details(?=[^>]*data-previous-six-heroes)(?=[^>]*\sopen(?:\s|>))[^>]*>/u',
            $html,
        );
        $this->assertStringContainsString('alt="確定時の封魔英雄"', $html);
        $this->assertSame($before, $this->competitionSnapshot());
    }

    public function test_previous_month_pending_or_absent_never_substitutes_an_older_finalized_season(): void
    {
        $this->season('2026-08', finalized: false, initialized: true);
        $pending = $this->season('2026-07', finalized: false);
        $older = $this->season('2026-06');
        $viewer = $this->character('前月待機閲覧者');
        $pendingHero = $this->character('七月未確定英雄');
        $olderHero = $this->character('六月の代替禁止英雄');

        $this->champions($pending, [
            SixHeroRoomKey::DIVINE_SPEED->value => $pendingHero,
        ]);
        $this->champions($older, [
            SixHeroRoomKey::MIRACLE->value => $olderHero,
        ]);

        $this->hallComponent($viewer)
            ->assertSee('2026年7月期')
            ->assertSee('結果確定中')
            ->assertDontSee('七月未確定英雄')
            ->assertDontSee('六月の代替禁止英雄');

        SixHeroChampion::query()->where('season_id', $pending->id)->delete();
        $pending->delete();

        $this->hallComponent($viewer)
            ->assertSee('2026年7月期')
            ->assertSee('前月の記録はありません。')
            ->assertDontSee('六月の代替禁止英雄');
    }

    public function test_room_history_and_character_achievements_show_vacancies_crowns_and_streaks_from_finalized_snapshots(): void
    {
        $current = $this->season('2026-08', finalized: false, initialized: true);
        $viewer = $this->character('冠と連覇の英雄');
        $unfinalizedHero = $this->character('進行中Seasonの誤混入英雄');
        $april = $this->season('2026-04');
        $may = $this->season('2026-05');
        $june = $this->season('2026-06');
        $july = $this->season('2026-07');

        $this->champions($april);
        $this->champions($may, [
            SixHeroRoomKey::DIVINE_SPEED->value => $viewer,
        ]);
        $this->champions($june, [
            SixHeroRoomKey::DIVINE_SPEED->value => $viewer,
            SixHeroRoomKey::MIRACLE->value => $viewer,
        ]);
        $this->champions($july, array_fill_keys(
            array_map(
                fn (SixHeroRoomKey $room): string => $room->value,
                SixHeroRoomKey::cases(),
            ),
            $viewer,
        ));
        $this->champions($current, [
            SixHeroRoomKey::DIVINE_SPEED->value => $unfinalizedHero,
        ]);

        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->assertSee('歴代 神速の英雄')
            ->assertSee('英雄獲得')
            ->assertSee('9回')
            ->assertSee('制覇した間')
            ->assertSee('6 / 6')
            ->assertSee('最高同時冠')
            ->assertSee('一冠')
            ->assertSee('二冠')
            ->assertSee('六冠')
            ->assertSee('現在3連覇')
            ->assertSee('最長3連覇')
            ->assertDontSee('1連覇')
            ->assertDontSee('進行中Seasonの誤混入英雄');

        $html = $component->html();
        $julyPosition = strpos($html, 'data-room-history-season="2026-07"');
        $junePosition = strpos($html, 'data-room-history-season="2026-06"');
        $mayPosition = strpos($html, 'data-room-history-season="2026-05"');
        $aprilPosition = strpos($html, 'data-room-history-season="2026-04"');

        $this->assertNotFalse($julyPosition);
        $this->assertNotFalse($junePosition);
        $this->assertNotFalse($mayPosition);
        $this->assertNotFalse($aprilPosition);
        $this->assertTrue($julyPosition < $junePosition);
        $this->assertTrue($junePosition < $mayPosition);
        $this->assertTrue($mayPosition < $aprilPosition);
        $this->assertStringContainsString('— 空位 —', $html);
        $this->assertSame(6, substr_count($html, 'data-achievement-room='));
        $this->assertStringContainsString('data-crown-season="2026-05"', $html);
        $this->assertStringContainsString('data-crown-season="2026-06"', $html);
        $this->assertStringContainsString('data-crown-season="2026-07"', $html);
    }

    public function test_ended_streak_keeps_longest_streak_without_showing_a_current_streak(): void
    {
        $this->season('2026-08', finalized: false, initialized: true);
        $viewer = $this->character('過去二連覇の英雄');
        $successor = $this->character('現在の確定英雄');
        $may = $this->season('2026-05');
        $june = $this->season('2026-06');
        $july = $this->season('2026-07');

        $this->champions($may, [SixHeroRoomKey::DIVINE_SPEED->value => $viewer]);
        $this->champions($june, [SixHeroRoomKey::DIVINE_SPEED->value => $viewer]);
        $this->champions($july, [SixHeroRoomKey::DIVINE_SPEED->value => $successor]);

        $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->assertSee('最長2連覇')
            ->assertDontSee('現在2連覇')
            ->assertDontSee('1連覇');
    }

    public function test_character_without_finalized_hero_history_has_a_normal_empty_state(): void
    {
        $this->season('2026-08', finalized: false, initialized: true);
        $viewer = $this->character('英雄未経験者');

        $this->hallComponent($viewer)
            ->assertSee('英雄獲得')
            ->assertSee('0回')
            ->assertSee('0 / 6')
            ->assertSee('最高同時冠')
            ->assertSee('なし')
            ->assertSee('まだ六英雄の記録はありません。')
            ->assertSee('歴代英雄の記録はまだありません。');
    }

    private function hallComponent(
        Character $viewer,
        SixHeroRoomKey $room = SixHeroRoomKey::SEAL_MAGIC,
    ): Testable {
        session(['current_character_id' => $viewer->id]);

        return Livewire::withQueryParams(['room' => $room->value])
            ->actingAs($viewer->user)
            ->test(SixHeroHallScreen::class);
    }

    private function season(
        string $key,
        bool $finalized = true,
        bool $initialized = true,
    ): SixHeroSeason {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo')->startOfMonth();
        $endsAt = $startsAt->copy()->addMonth();

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $finalized ? $endsAt : null,
            'ranking_initialized_at' => $initialized ? $startsAt : null,
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

    private function ranking(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
        int $rank,
    ): SixHeroRanking {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $character->id,
            'rank' => $rank,
            'official_attack_wins' => 0,
            'official_attack_losses' => 0,
            'defense_wins' => 0,
            'defense_losses' => 0,
            'registered_at' => $season->starts_at,
            'first_place_since' => $rank === 1 ? $season->starts_at : null,
        ]);
    }

    /**
     * @param  array<string, Character|array{0: Character, 1: string}>  $heroes
     * @param  array<string, array{0: string, 1: int, 2: int}>  $vacancies
     */
    private function champions(
        SixHeroSeason $season,
        array $heroes = [],
        array $vacancies = [],
    ): void {
        foreach (SixHeroRoomKey::cases() as $room) {
            $heroValue = $heroes[$room->value] ?? null;
            $hero = is_array($heroValue) ? $heroValue[0] : $heroValue;
            $snapshotName = is_array($heroValue)
                ? $heroValue[1]
                : $hero?->name;
            [$vacancyReason, $registeredCount, $officialBattleCount] = $vacancies[$room->value]
                ?? [SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY, 0, 0];

            SixHeroChampion::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'character_id' => $hero?->id,
                'character_id_snapshot' => $hero?->id,
                'character_name_snapshot' => $snapshotName,
                'is_vacant' => $hero === null,
                'vacancy_reason' => $hero === null ? $vacancyReason : null,
                'registered_count' => $hero === null ? $registeredCount : 12,
                'official_battle_count' => $hero === null ? $officialBattleCount : 20,
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
