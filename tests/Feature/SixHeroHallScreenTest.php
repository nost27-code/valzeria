<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\MainScreenShell;
use App\Livewire\NavMenu;
use App\Livewire\SixHeroHallScreen;
use App\Livewire\SixHeroRoomRanking;
use App\Models\Character;
use App\Models\PlayerValmon;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Support\SixHeroRoomUiCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class SixHeroHallScreenTest extends TestCase
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

    public function test_internal_preview_route_is_authenticated_and_closed_when_flag_is_off(): void
    {
        $this->get(route('six-heroes.index'))->assertRedirect('/');
        $this->get(route('six-heroes.battle-result'))->assertRedirect('/');

        config(['features.six_hero_ui_enabled' => false]);
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('six-heroes.index'))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('six-heroes.battle-result'))
            ->assertNotFound();
    }

    public function test_preview_route_uses_the_existing_authenticated_game_middleware(): void
    {
        $this->readySeason();
        $viewer = $this->character('HTTP閲覧者');
        $valmon = ValmonMaster::query()->create([
            'valmon_key' => 'six-hero-screen-route-test',
            'name' => '六極殿案内モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $viewer->id,
            'valmon_master_id' => $valmon->id,
            'is_partner' => true,
            'obtained_source' => 'test',
            'obtained_at' => now(),
        ]);
        session(['current_character_id' => $viewer->id]);

        $this->actingAs($viewer->user)
            ->get(route('six-heroes.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('current_location', 'colosseum');

        $this->actingAs($viewer->user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-six-hero-home-tab', false)
            ->assertSee('六極殿')
            ->assertSee('公式戦 残り 5 / 5');

        $this->actingAs($viewer->user)
            ->get(route('six-heroes.battle-result'))
            ->assertRedirect(route('six-heroes.index'));

        config(['features.six_hero_ui_enabled' => false]);

        $this->actingAs($viewer->user)
            ->get(route('six-heroes.index'))
            ->assertNotFound();
    }

    public function test_home_colosseum_tab_switches_between_legacy_arena_and_six_hero_hall(): void
    {
        $viewer = $this->character('導線確認者');
        session(['current_character_id' => $viewer->id]);
        $this->actingAs($viewer->user);

        config(['features.six_hero_ui_enabled' => false]);
        Livewire::test(NavMenu::class)
            ->assertSeeHtml('data-bottom-navigation')
            ->assertSee('闘技場');
        session(['current_location' => 'colosseum']);
        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'colosseum')
            ->assertSeeHtml('data-legacy-arena-home-tab')
            ->assertSee('ランク戦に挑む')
            ->assertDontSeeHtml('data-six-hero-home-tab');

        config(['features.six_hero_ui_enabled' => true]);
        Livewire::test(NavMenu::class)
            ->assertSeeHtml('data-bottom-navigation')
            ->assertSee('闘技場');

        $this->readySeason();
        session(['current_location' => 'colosseum']);
        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'colosseum')
            ->assertSeeHtml('data-six-hero-home-tab')
            ->assertSeeHtml('data-six-hero-hall')
            ->assertDontSeeHtml('data-legacy-arena-home-tab')
            ->assertSeeHtml('data-color-scheme="light"');
    }

    public function test_legacy_arena_battle_endpoints_are_retired(): void
    {
        $viewer = $this->character('旧闘技場確認者');
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($viewer->user);

        $this->post('/battle/pvp-random')->assertNotFound();
        $this->post('/battle/pvp/'.$viewer->id)->assertNotFound();
        $this->get('/battle/pvp-result')->assertNotFound();
        $this->get('/colosseum/ranking')
            ->assertRedirect(route('home'))
            ->assertSessionHas('current_location', 'colosseum');
    }

    public function test_legacy_arena_battle_endpoints_remain_available_while_the_feature_flag_is_off(): void
    {
        config(['features.six_hero_ui_enabled' => false]);
        $viewer = $this->character('従来闘技場利用者');
        session(['current_character_id' => $viewer->id]);
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($viewer->user);

        $this->get('/colosseum/ranking')
            ->assertOk()
            ->assertSee('闘技場ランキング');
        $this->post('/battle/pvp-random')
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', '闘技場ランキングに参加していません。');
        $this->post('/battle/pvp/'.$viewer->id)
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', '自分自身とは戦えません。');
        $this->get('/battle/pvp-result')
            ->assertRedirect(route('home'));
    }

    public function test_home_tab_uses_the_light_six_hero_screen_and_legacy_npc_updates_follow_the_feature_flag(): void
    {
        $mainScreen = (string) file_get_contents(resource_path('views/livewire/main-screen.blade.php'));
        $hallScreen = (string) file_get_contents(resource_path('views/livewire/six-hero-hall-screen.blade.php'));
        $rankingScreen = (string) file_get_contents(resource_path('views/livewire/six-hero-room-ranking.blade.php'));
        $battleResult = (string) file_get_contents(resource_path('views/six-heroes/battle-result.blade.php'));
        $styles = (string) file_get_contents(resource_path('css/app.css'));
        $schedule = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('<livewire:six-hero-hall-screen />', $mainScreen);
        $this->assertStringContainsString('<livewire:colosseum-screen />', $mainScreen);
        $this->assertStringContainsString('data-color-scheme="light"', $hallScreen);
        $this->assertStringNotContainsString('data-battle-result-modal', $hallScreen);
        $this->assertStringContainsString('data-six-hero-battle-result-page', $battleResult);
        $this->assertStringContainsString('label="六極殿へ戻る"', $battleResult);
        $this->assertStringNotContainsString('six-hero-light', $hallScreen);
        $this->assertStringNotContainsString('six-hero-light', $rankingScreen);
        $this->assertStringNotContainsString('six-hero-light', $battleResult);
        $this->assertStringNotContainsString('.six-hero-light', $styles);
        $this->assertStringNotContainsString('--color-valzeria-blue-dark', $styles);
        $this->assertStringNotContainsString('--color-valzeria-gold-dark', $styles);

        foreach ([$hallScreen, $rankingScreen, $battleResult] as $lightScreen) {
            $this->assertStringNotContainsString('bg-slate-950/85', $lightScreen);
            $this->assertStringNotContainsString('bg-slate-950/80', $lightScreen);
            $this->assertStringNotContainsString('bg-slate-950/30', $lightScreen);
            $this->assertStringNotContainsString('bg-slate-950"', $lightScreen);
            $this->assertStringNotContainsString('bg-slate-900', $lightScreen);
            $this->assertStringNotContainsString('border-slate-700', $lightScreen);
            $this->assertStringNotContainsString('text-slate-300', $lightScreen);
            $this->assertStringNotContainsString('text-amber-200', $lightScreen);
        }
        $this->assertStringContainsString("if (! (bool) config('features.six_hero_ui_enabled', false))", $schedule);
        $this->assertSame(3, substr_count($schedule, 'arena:npc-auto-battles'));

        foreach (SixHeroRoomKey::cases() as $room) {
            $this->assertStringNotContainsString('-950', SixHeroRoomUiCatalog::accentClasses($room));
        }
    }

    public function test_screen_entry_lazily_creates_current_season_and_carries_previous_ranking(): void
    {
        $previous = $this->season(
            '2026-07',
            '2026-07-01 00:00:00',
            '2026-08-01 00:00:00',
            initialized: true,
        );
        $leader = $this->character('前月首位');
        $viewer = $this->character('前月3位');
        $this->ranking($previous, SixHeroRoomKey::DIVINE_SPEED, $leader, 1)
            ->update(['official_attack_wins' => 9]);
        $this->ranking($previous, SixHeroRoomKey::DIVINE_SPEED, $viewer, 3)
            ->update(['official_attack_losses' => 4]);
        $previous->update(['finalized_at' => '2026-08-01 00:00:00']);
        $this->vacantChampionSnapshots($previous);

        $this->assertDatabaseMissing('six_hero_seasons', ['season_key' => '2026-08']);

        $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->assertSee('2026年8月期')
            ->assertSee('自分の順位：2位')
            ->assertSee('公式戦 残り 5 / 5');

        $current = SixHeroSeason::query()
            ->where('season_key', '2026-08')
            ->firstOrFail();
        $copied = SixHeroRanking::query()
            ->where('season_id', $current->id)
            ->where('room_key', SixHeroRoomKey::DIVINE_SPEED)
            ->where('character_id', $viewer->id)
            ->firstOrFail();

        $this->assertNotNull($current->ranking_initialized_at);
        $this->assertSame(2, $copied->rank);
        $this->assertSame(0, $copied->official_attack_wins);
        $this->assertSame(0, $copied->official_attack_losses);
        $this->assertNull($copied->first_place_since);
        $this->assertTrue($copied->registered_at->equalTo($current->starts_at));
    }

    public function test_screen_shows_all_rooms_current_status_and_selected_room_remaining_attempts_without_creating_usage(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('閲覧者');
        $leaders = [];

        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $leaders[$room->value] = $this->character("{$room->label()}首位");
            $this->ranking($season, $room, $leaders[$room->value], 1);
            if ($index === 0) {
                $this->ranking($season, $room, $viewer, 2);
            }
        }

        $component = $this->hallComponent($viewer);

        $component
            ->assertSee('2026年8月期')
            ->assertSee('現在の六英雄')
            ->assertSee('進行中の各間で現在1位の冒険者です')
            ->assertSee('公式戦 残り 5 / 5')
            ->assertSee('現在首位')
            ->assertSee('現在1位')
            ->assertSee('自分の順位')
            ->assertSee('2位')
            ->assertDontSee('英雄成立条件');

        foreach (SixHeroRoomKey::cases() as $room) {
            $component->assertSee($room->label());
        }

        $html = $component->html();
        $this->assertFileExists(public_path('images/six_heroes/six_hero_chambers.webp'));
        $this->assertStringContainsString('images/six_heroes/six_hero_chambers.webp', $html);
        $this->assertMatchesRegularExpression('/six_hero_chambers\.webp\?v=\d+/', $html);
        $this->assertStringContainsString('class="relative aspect-[3/4] w-full"', $html);
        $this->assertStringContainsString('w-[116%]', $html);
        $this->assertStringNotContainsString('max-w-[360px]', $html);
        $this->assertStringContainsString('w-[21%]', $html);
        $this->assertStringContainsString('bg-white/75 shadow-sm ring-1 ring-white/90', $html);
        $this->assertStringContainsString('在位 19日目', $html);
        $this->assertStringContainsString('data-six-hero-top-layout', $html);
        $this->assertStringContainsString('lg:grid-cols-[minmax(0,1.08fr)_minmax(20rem,0.92fr)]', $html);
        $this->assertSame(6, substr_count($html, 'data-current-six-hero-room='));
        $this->assertSame(6, substr_count($html, 'data-current-six-hero-character-id='));
        $this->assertSame(6, substr_count($html, 'data-current-six-hero-room-label'));
        $this->assertSame(6, count(array_unique(array_map(
            static fn (SixHeroRoomKey $room): string => json_encode(
                SixHeroRoomUiCatalog::chamberPosition($room),
                JSON_THROW_ON_ERROR,
            ),
            SixHeroRoomKey::cases(),
        ))));

        $component
            ->assertDontSee('現在の英雄')
            ->assertDontSee('月間英雄：')
            ->assertSee('六英雄戦の遊び方')
            ->assertSee('それぞれ独立したランキングです')
            ->assertSee('複数の間へ同時に参加できます')
            ->assertSee('自分の直上3人です')
            ->assertSee('相手の順位を奪います')
            ->assertSee('各間ごとに1日5戦です')
            ->assertSee('相性確認は回数無制限')
            ->assertSee('冒険者訓練所')
            ->assertDontSee('登録者8人以上・有効公式戦10戦以上')
            ->assertDontSee('Room内有効公式戦')
            ->assertSee('順位は翌月へ引き継がれ')
            ->assertDontSee('1位本人が公式攻撃5勝');
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
    }

    public function test_current_leaders_show_tenure_new_self_glow_and_current_multi_room_crowns(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('二間首位の閲覧者');
        $defender = $this->character('前首位');

        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $leader = $index < 2
                ? $viewer
                : $this->character("{$room->label()}別首位");
            $ranking = $this->ranking($season, $room, $leader, 1);
            $ranking->first_place_since = $index === 0
                ? now()->subHours(2)
                : now()->subDays(2);
            $ranking->save();
        }

        SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::SEAL_MAGIC,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_COMPLETED,
            'attacker_id' => $viewer->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'is_attacker_win' => true,
            'rank_changed' => true,
            'attacker_old_rank' => 2,
            'attacker_new_rank' => 1,
            'defender_old_rank' => 1,
            'defender_new_rank' => 2,
            'turn_count' => 4,
            'attacker_hp_ratio' => 0.7,
            'defender_hp_ratio' => 0,
            'daily_attempt_number' => 1,
            'started_at' => now()->subHours(2)->subMinute(),
            'resolved_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);

        $html = $this->hallComponent($viewer)->html();

        $this->assertSame(2, substr_count($html, 'data-current-six-hero-self'));
        $this->assertSame(1, substr_count($html, 'data-current-six-hero-new'));
        $this->assertSame(2, substr_count($html, 'data-current-six-hero-crowns="2"'));
        $this->assertStringContainsString('shadow-[0_0_22px_rgba(251,191,36,0.95)]', $html);
        $this->assertStringContainsString('二間首位の閲覧者', $html);
        $this->assertStringContainsString('在位 1日目', $html);
        $this->assertStringContainsString('在位 3日目', $html);
        $this->assertStringContainsString('NEW', $html);
        $this->assertStringContainsString('👑2', $html);
        $this->assertStringContainsString('戦績付き冒険者カードを見る', $html);
    }

    public function test_remaining_attempts_are_isolated_and_eligibility_progress_is_not_shown(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('挑戦者');
        $characters = [$viewer];
        for ($index = 2; $index <= 7; $index++) {
            $characters[] = $this->character("参加者{$index}");
        }
        foreach ($characters as $index => $character) {
            $ranking = $this->ranking(
                $season,
                SixHeroRoomKey::SEAL_MAGIC,
                $character,
                $index + 1,
            );
            if ($index === 0) {
                $ranking->update([
                    'official_attack_wins' => 4,
                    'official_attack_losses' => 5,
                ]);
            }
        }
        DB::table('six_hero_daily_usages')->insert([
            'character_id' => $viewer->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 2,
            'official_attempts_by_room' => json_encode([
                SixHeroRoomKey::SEAL_MAGIC->value => 2,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = $this->hallComponent($viewer)
            ->assertSee('公式戦 残り 3 / 5')
            ->assertDontSee('英雄成立条件')
            ->assertDontSee('登録者 7 / 8人')
            ->assertDontSee('有効公式戦 9 / 10戦');

        $component
            ->call('selectRoom', SixHeroRoomKey::SEAL_BLADE->value)
            ->assertSee('公式戦 残り 5 / 5');

        $eighth = $this->character('参加者8');
        $this->ranking($season, SixHeroRoomKey::SEAL_MAGIC, $eighth, 8)
            ->update(['official_attack_wins' => 1]);

        $this->hallComponent($viewer)
            ->assertDontSee('登録者 8 / 8人')
            ->assertDontSee('有効公式戦 10 / 10戦')
            ->assertDontSee('成立条件を満たしています');
    }

    public function test_candidates_are_the_closest_three_higher_players_ordered_highest_first_and_room_switch_is_isolated(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('候補閲覧者');
        $speedNames = ['神速1位', '神速2位', '神速3位', '神速4位'];
        $speedCharacters = [];
        foreach ($speedNames as $index => $name) {
            $speedCharacters[] = $this->character($name);
            $this->ranking(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $speedCharacters[$index],
                $index + 1,
            );
        }
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $viewer, 5);
        $this->ranking(
            $season,
            SixHeroRoomKey::MIRACLE,
            $this->character('奇跡だけの首位'),
            1,
        );

        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED);

        $candidateHtml = $component->html();
        $rankTwoPosition = strpos(
            $candidateHtml,
            'data-candidate-character-id="'.$speedCharacters[1]->id.'"',
        );
        $rankThreePosition = strpos(
            $candidateHtml,
            'data-candidate-character-id="'.$speedCharacters[2]->id.'"',
        );
        $rankFourPosition = strpos(
            $candidateHtml,
            'data-candidate-character-id="'.$speedCharacters[3]->id.'"',
        );

        $this->assertNotFalse($rankTwoPosition);
        $this->assertNotFalse($rankThreePosition);
        $this->assertNotFalse($rankFourPosition);
        $this->assertTrue(
            $rankTwoPosition < $rankThreePosition && $rankThreePosition < $rankFourPosition,
            'Challenge candidates should be rendered from the highest rank downward.',
        );

        $component
            ->assertSeeInOrder(['神速2位', '神速3位', '神速4位'])
            ->assertSeeHtml('data-candidate-character-id="'.$speedCharacters[3]->id.'"')
            ->assertSeeHtml('data-candidate-character-id="'.$speedCharacters[2]->id.'"')
            ->assertSeeHtml('data-candidate-character-id="'.$speedCharacters[1]->id.'"')
            ->assertDontSeeHtml('data-candidate-character-id="'.$speedCharacters[0]->id.'"')
            ->call('selectRoom', SixHeroRoomKey::MIRACLE->value)
            ->assertSet('selectedRoom', SixHeroRoomKey::MIRACLE->value)
            ->assertSee('奇跡だけの首位')
            ->assertDontSee('神速4位');
    }

    public function test_room_ranking_is_paginated_as_an_isolated_livewire_component(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('ページ閲覧者');
        $rankingCharacters = [];
        for ($rank = 1; $rank <= 21; $rank++) {
            $rankingCharacters[] = $this->character(sprintf('封魔順位%02d', $rank));
            $this->ranking(
                $season,
                SixHeroRoomKey::SEAL_MAGIC,
                $rankingCharacters[$rank - 1],
                $rank,
            );
        }

        session(['current_character_id' => $viewer->id]);
        Livewire::withQueryParams(['room' => 'unknown-room'])
            ->actingAs($viewer->user)
            ->test(SixHeroHallScreen::class)
            ->assertSet('selectedRoom', SixHeroRoomKey::SEAL_MAGIC->value);

        $component = Livewire::actingAs($viewer->user)
            ->test(SixHeroRoomRanking::class, [
                'seasonId' => $season->id,
                'roomKey' => SixHeroRoomKey::SEAL_MAGIC->value,
            ])
            ->assertSet('currentCharacterId', $viewer->id)
            ->assertSee('封魔順位20')
            ->assertSee('前へ')
            ->assertSee('次へ')
            ->assertSee('表示中:')
            ->assertSeeHtml('data-six-hero-room-ranking')
            ->assertSeeHtml('data-ranking-loading')
            ->assertSeeHtml('wire:target="gotoPage, previousPage, nextPage"')
            ->assertDontSee('pagination.previous')
            ->assertDontSee('pagination.next')
            ->assertDontSee('Showing')
            ->assertDontSeeHtml('data-ranking-character-id="'.$rankingCharacters[20]->id.'"');

        $component
            ->call('gotoPage', 2, 'roomPage')
            ->assertSee('封魔順位21')
            ->assertDontSeeHtml('data-ranking-character-id="'.$rankingCharacters[0]->id.'"');
    }

    public function test_registration_uses_the_existing_service_and_is_idempotent_without_competition_side_effects(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('新規参加者');

        $component = $this->hallComponent($viewer);
        $component
            ->assertSee('次月以降は前月順位を引き継いで自動登録')
            ->call('registerRoom', SixHeroRoomKey::SEAL_MAGIC->value)
            ->assertHasNoErrors()
            ->assertSee('封魔の間へ参加登録しました')
            ->call('registerRoom', SixHeroRoomKey::SEAL_MAGIC->value)
            ->assertHasNoErrors()
            ->assertSee('封魔の間には参加済みです');

        $this->assertDatabaseCount('six_hero_rankings', 1);
        $this->assertDatabaseHas('six_hero_rankings', [
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::SEAL_MAGIC->value,
            'character_id' => $viewer->id,
            'rank' => 1,
        ]);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_not_ready_state_and_registration_leave_competition_tables_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
        $previous = $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            initialized: true,
        );
        $current = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            initialized: false,
        );
        $viewer = $this->character('準備待ち参加者');
        $defender = $this->character('未完了戦の相手');
        SixHeroBattleLog::query()->create([
            'season_id' => $previous->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_STARTED,
            'attacker_id' => $viewer->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => '2026-08-31 23:59:59',
        ]);

        $component = $this->hallComponent($viewer)
            ->assertSee('月次ランキング準備中')
            ->assertDontSee('参加登録する');

        $component
            ->call('registerRoom', SixHeroRoomKey::DIVINE_SPEED->value)
            ->assertHasErrors('registration')
            ->assertSee('月次ランキング準備中');

        $this->assertNull($current->fresh()->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $current->id,
        ]);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
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

    private function readySeason(): SixHeroSeason
    {
        return $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            initialized: true,
        );
    }

    private function season(
        string $key,
        string $startsAt,
        string $endsAt,
        bool $initialized,
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
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

    private function vacantChampionSnapshots(SixHeroSeason $season): void
    {
        foreach (SixHeroRoomKey::cases() as $room) {
            SixHeroChampion::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'character_id' => null,
                'character_id_snapshot' => null,
                'character_name_snapshot' => null,
                'is_vacant' => true,
                'vacancy_reason' => SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY,
                'registered_count' => 0,
                'official_battle_count' => 0,
                'official_attack_wins' => null,
                'official_attack_losses' => null,
                'defense_wins' => null,
                'defense_losses' => null,
            ]);
        }
    }
}
