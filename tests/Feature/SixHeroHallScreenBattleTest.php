<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\SixHeroHallScreen;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\Battle\PvPBattleResolution;
use App\Services\PvPBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SixHeroHallScreenBattleTest extends TestCase
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

    public function test_official_ctas_are_limited_to_the_closest_three_higher_players_and_use_confirmation(): void
    {
        $season = $this->readySeason();
        $characters = [];
        for ($rank = 1; $rank <= 10; $rank++) {
            $characters[$rank] = $this->character("順位{$rank}");
            $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $characters[$rank], $rank);
        }
        $viewer = $characters[10];

        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED);
        $component
            ->assertSee('公式戦前に相性を試す')
            ->assertSee('PvP用戦技セット')
            ->assertSee('この間の特殊ルール')
            ->assertSee('現在の相手ビルド');
        foreach ([9, 8, 7] as $rank) {
            $component->assertSeeHtml(
                'data-official-battle-character-id="'.$characters[$rank]->id.'"',
            );
            $component->assertSeeHtml(
                'data-practice-battle-character-id="'.$characters[$rank]->id.'"',
            );
        }
        $component
            ->assertDontSeeHtml('data-official-battle-character-id="'.$characters[6]->id.'"')
            ->assertDontSeeHtml('data-ranking-practice-character-id=')
            ->call(
                'openBattleConfirmation',
                'official',
                $characters[8]->id,
            )
            ->assertSet('battleConfirmation.mode', 'official')
            ->assertSee('公式戦の確認')
            ->assertSee('開始後、勝敗にかかわらず1回消費')
            ->assertSee('現在の残り 5 / 5')
            ->assertSeeHtml('data-confirm-battle-button');

        $html = $component->html();
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('戦闘中...', $html);
    }

    public function test_rank_one_has_no_battle_cta_in_the_current_ranking(): void
    {
        $season = $this->readySeason();
        $viewer = $this->character('現在首位');
        $other = $this->character('研究相手');
        $this->ranking($season, SixHeroRoomKey::SEAL_MAGIC, $viewer, 1);
        $this->ranking($season, SixHeroRoomKey::SEAL_MAGIC, $other, 2);

        $this->hallComponent($viewer)
            ->assertSee('現在1位のため、上位候補はいません')
            ->assertDontSeeHtml('data-official-battle-character-id=')
            ->assertDontSeeHtml('data-ranking-practice-character-id=');
    }

    public function test_official_victory_updates_rank_attempts_leader_battle_count_and_eligibility_immediately(): void
    {
        $season = $this->readySeason();
        $leader = $this->character('旧首位');
        $viewer = $this->character('新首位');
        $participants = [$leader, $viewer];
        for ($index = 3; $index <= 8; $index++) {
            $participants[] = $this->character("成立参加者{$index}");
        }
        foreach ($participants as $index => $character) {
            $ranking = $this->ranking(
                $season,
                SixHeroRoomKey::MIRACLE,
                $character,
                $index + 1,
            );
            if ($index === 0) {
                $ranking->update(['official_attack_wins' => 9]);
            }
        }
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(true));

        $component = $this->hallComponent($viewer, SixHeroRoomKey::MIRACLE)
            ->assertDontSee('英雄成立条件')
            ->assertDontSee('有効公式戦 9 / 10戦')
            ->call('openBattleConfirmation', 'official', $leader->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'))
            ->assertSet('battleResult.mode', 'official')
            ->assertSet('battleResult.attackerWon', true)
            ->assertSet('battleResult.rankChangeStatus', 'changed')
            ->assertSet('battleResult.attackerOldRank', 2)
            ->assertSet('battleResult.attackerNewRank', 1);

        $this->battleResultResponse($viewer)
            ->assertSee('勝利！')
            ->assertSee('残り 4 / 5');

        $this->hallComponent($viewer, SixHeroRoomKey::MIRACLE)
            ->assertDontSee('有効公式戦 10 / 10戦')
            ->assertDontSee('成立条件を満たしています')
            ->assertSee('現在首位')
            ->assertSee($viewer->name)
            ->assertSee('現在1位のため、上位候補はいません');

        $officialBattleCount = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::MIRACLE)
            ->sum(DB::raw('official_attack_wins + official_attack_losses'));
        $this->assertSame(10, (int) $officialBattleCount);

        $this->assertSame(1, $this->freshRanking($season, $viewer)->rank);
        $this->assertSame(2, $this->freshRanking($season, $leader)->rank);
        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $viewer->id,
            'official_attempts' => 1,
        ]);
        $this->assertDatabaseHas('six_hero_battle_logs', [
            'season_id' => $season->id,
            'attacker_id' => $viewer->id,
            'defender_id' => $leader->id,
            'status' => SixHeroBattleLog::STATUS_COMPLETED,
        ]);
        $this->assertSame(4, $component->get('battleResult.officialAttemptsRemaining'));
    }

    public function test_official_defeat_consumes_one_attempt_without_changing_rank(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('上位防衛者');
        $middle = $this->character('中間順位');
        $viewer = $this->character('敗北挑戦者');
        $this->ranking($season, SixHeroRoomKey::SEAL_BLADE, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::SEAL_BLADE, $middle, 2);
        $this->ranking($season, SixHeroRoomKey::SEAL_BLADE, $viewer, 3);
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(false));

        $this->hallComponent($viewer, SixHeroRoomKey::SEAL_BLADE)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'))
            ->assertSet('battleResult.rankChangeStatus', 'unchanged_loss');

        $this->battleResultResponse($viewer)
            ->assertSee('敗北')
            ->assertSee('順位変動なし')
            ->assertSee('現在 3位')
            ->assertSee('残り 4 / 5');

        $this->assertSame(3, $this->freshRanking($season, $viewer)->rank);
        $this->assertDatabaseHas('six_hero_rankings', [
            'season_id' => $season->id,
            'character_id' => $viewer->id,
            'official_attack_losses' => 1,
        ]);
    }

    public function test_official_win_without_rank_change_explains_concurrent_ranking_movement(): void
    {
        $season = $this->readySeason();
        $leader = $this->character('1位');
        $defender = $this->character('対戦開始時2位');
        $third = $this->character('3位');
        $viewer = $this->character('対戦開始時4位');
        $this->ranking($season, SixHeroRoomKey::REVERSE_TIME, $leader, 1);
        $defenderRanking = $this->ranking(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            $defender,
            2,
        );
        $this->ranking($season, SixHeroRoomKey::REVERSE_TIME, $third, 3);
        $viewerRanking = $this->ranking(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            $viewer,
            4,
        );
        $this->bindBattle(function () use ($defenderRanking, $viewerRanking): PvPBattleResolution {
            SixHeroRanking::query()->whereKey($defenderRanking->id)->update([
                'rank' => -1 * $defenderRanking->id,
            ]);
            SixHeroRanking::query()->whereKey($viewerRanking->id)->update(['rank' => 2]);
            SixHeroRanking::query()->whereKey($defenderRanking->id)->update(['rank' => 4]);

            return $this->resolution(true);
        });

        $this->hallComponent($viewer, SixHeroRoomKey::REVERSE_TIME)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'))
            ->assertSet('battleResult.attackerWon', true)
            ->assertSet('battleResult.rankChangeStatus', 'unchanged_concurrent');

        $this->battleResultResponse($viewer)
            ->assertSee('勝利！')
            ->assertSee('対戦中のランキング変動により順位変更はありませんでした。');
    }

    public function test_practice_at_the_daily_limit_can_target_any_rank_without_competitive_side_effects(): void
    {
        $season = $this->readySeason();
        $leader = $this->character('練習相手の1位');
        $viewer = $this->character('練習する50位');
        $leaderRanking = $this->ranking(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            $leader,
            1,
        );
        $viewerRanking = $this->ranking(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            $viewer,
            50,
        );
        $leaderRanking->update([
            'official_attack_wins' => 3,
            'defense_wins' => 4,
        ]);
        $viewerRanking->update([
            'official_attack_losses' => 2,
            'defense_losses' => 5,
        ]);
        DB::table('six_hero_daily_usages')->insert([
            'character_id' => $viewer->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 5,
            'official_attempts_by_room' => json_encode([
                SixHeroRoomKey::BURNING_LIFE->value => 5,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->rankingSnapshots($season);
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(true));

        $this->hallComponent($viewer, SixHeroRoomKey::BURNING_LIFE)
            ->assertSee('公式戦 残り 0 / 5')
            ->assertSee('本日の公式戦は終了しました')
            ->assertDontSeeHtml('data-ranking-practice-character-id=')
            ->call('openBattleConfirmation', 'practice', $leader->id)
            ->assertSee('相性確認')
            ->assertDontSee('相性確認の確認')
            ->assertSee('順位・公式戦績・公式戦回数に影響しません')
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'))
            ->assertSet('battleResult.mode', 'practice');

        $this->battleResultResponse($viewer)
            ->assertSee('相性確認結果')
            ->assertSee('相性確認のため、順位・公式戦績・公式戦回数には影響しません。');

        $this->hallComponent($viewer, SixHeroRoomKey::BURNING_LIFE)
            ->assertSee('公式戦 残り 0 / 5');

        $this->assertSame($before, $this->rankingSnapshots($season));
        $this->assertDatabaseCount('six_hero_daily_usages', 1);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_self_spoofed_room_and_stale_official_targets_are_rejected_without_consumption(): void
    {
        $season = $this->readySeason();
        $leader = $this->character('候補1位');
        $target = $this->character('候補2位');
        $third = $this->character('候補3位');
        $viewer = $this->character('挑戦者4位');
        $fifth = $this->character('5位');
        $otherRoom = $this->character('別Room登録者');
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $leader, 1);
        $targetRanking = $this->ranking(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $target,
            2,
        );
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $third, 3);
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $viewer, 4);
        $fifthRanking = $this->ranking(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $fifth,
            5,
        );
        $this->ranking($season, SixHeroRoomKey::MIRACLE, $otherRoom, 1);
        $this->bindNoBattle();

        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->call('openBattleConfirmation', 'practice', $viewer->id)
            ->assertSet('battleConfirmation', [])
            ->call('openBattleConfirmation', 'practice', $otherRoom->id)
            ->assertSet('battleConfirmation', [])
            ->call('openBattleConfirmation', 'official', $target->id)
            ->assertSet('battleConfirmation.mode', 'official');

        SixHeroRanking::query()->whereKey($targetRanking->id)->update([
            'rank' => -1 * $targetRanking->id,
        ]);
        SixHeroRanking::query()->whereKey($fifthRanking->id)->update(['rank' => 2]);
        SixHeroRanking::query()->whereKey($targetRanking->id)->update(['rank' => 5]);

        $component
            ->call('executeConfirmedBattle')
            ->assertSet('battleConfirmation', [])
            ->assertSee('ランキングが更新されました。最新の挑戦候補を表示しました。');

        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_not_ready_actions_leave_competition_tables_unchanged_and_show_safe_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
        $previous = $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            true,
        );
        $current = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            false,
        );
        $viewer = $this->character('準備待ち挑戦者');
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
        $this->bindNoBattle();

        $this->hallComponent($viewer)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->assertSet('battleConfirmation', [])
            ->call('openBattleConfirmation', 'practice', $defender->id)
            ->assertSet('battleConfirmation', [])
            ->assertSee('月次ランキングを準備しています。準備完了後にもう一度お試しください。');

        $this->assertNull($current->fresh()->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', ['season_id' => $current->id]);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
    }

    public function test_confirmation_opened_before_month_boundary_cannot_start_a_new_month_battle(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('8月上位');
        $viewer = $this->character('8月挑戦者');
        $this->ranking($season, SixHeroRoomKey::SEAL_MAGIC, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::SEAL_MAGIC, $viewer, 2);
        $this->bindNoBattle();

        $component = $this->hallComponent($viewer)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->assertSet('battleConfirmation.seasonKey', '2026-08');

        Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:01', 'Asia/Tokyo'));

        $component
            ->call('executeConfirmedBattle')
            ->assertSet('battleConfirmation', [])
            ->assertSee('ランキングが更新されました。最新の挑戦候補を表示しました。');

        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_official_runtime_exception_is_hidden_and_refreshes_the_consumed_attempt(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('技術例外の相手');
        $viewer = $this->character('技術例外の挑戦者');
        $this->ranking($season, SixHeroRoomKey::SEAL_BLADE, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::SEAL_BLADE, $viewer, 2);
        $this->bindBattle(
            static fn (): never => throw new RuntimeException('SECRET_RUNTIME_DETAIL'),
        );

        $this->hallComponent($viewer, SixHeroRoomKey::SEAL_BLADE)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertDontSee('SECRET_RUNTIME_DETAIL')
            ->assertSee('戦闘処理中に問題が発生しました。最新の状態を再読み込みしました。')
            ->assertSee('公式戦 残り 4 / 5');

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $viewer->id,
            'official_attempts' => 1,
        ]);
        $this->assertDatabaseHas('six_hero_battle_logs', [
            'attacker_id' => $viewer->id,
            'status' => SixHeroBattleLog::STATUS_FAILED,
            'failure_code' => SixHeroBattleLog::FAILURE_BATTLE_RUNTIME,
        ]);
    }

    public function test_completed_battle_redirects_to_a_standard_result_page_with_a_bottom_return_button(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('通常ページの相手');
        $viewer = $this->character('通常ページの挑戦者');
        $this->ranking($season, SixHeroRoomKey::MIRACLE, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::MIRACLE, $viewer, 2);
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(true));

        $this->hallComponent($viewer, SixHeroRoomKey::MIRACLE)
            ->call('openBattleConfirmation', 'practice', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'));

        $response = $this->battleResultResponse($viewer);

        $response
            ->assertSee('相性確認結果')
            ->assertSee('固定戦闘ログ1')
            ->assertSee('六極殿へ戻る')
            ->assertSee('data-six-hero-battle-result-page', escape: false)
            ->assertDontSee('data-battle-result-modal', escape: false);

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, '六極殿へ戻る'),
            strpos($html, 'data-battle-outcome'),
        );
    }

    public function test_result_uses_the_standard_combatant_and_styled_log_presentation_without_allowing_script_html(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('<script>opponent()</script>');
        $viewer = $this->character('表示確認者');
        $this->ranking($season, SixHeroRoomKey::MIRACLE, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::MIRACLE, $viewer, 2);
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(
            true,
            [
                '<span class="battle-log-job-art-title battle-log-job-art-title--ultimate battle-log-job-art-tooltip">'
                    .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">【奥義】星砕き</button>'
                    .'<span class="battle-log-job-art-tooltip-panel">'
                    .'<span class="battle-log-job-art-tooltip-label">戦技の効果</span>'
                    .'<span class="battle-log-job-art-tooltip-description">星を砕く威力を放つ。</span>'
                    .'</span></span>'
                    .'<script>logAttack()</script>',
                '<span class="text-blue-600 font-bold text-lg">【必殺技】蒼天撃</span>',
                '<span class="text-blue-700 font-bold">表示確認者：'
                    .'<span class="battle-log-job-art-tooltip">'
                    .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">剣勢</button>'
                    .'<span class="battle-log-job-art-tooltip-panel">'
                    .'<span class="battle-log-job-art-tooltip-label">剣勢の獲得方法</span>'
                    .'<span class="battle-log-job-art-tooltip-description">始動使用で+4。受け流し成功 +1。</span>'
                    .'</span></span> +1（2/12）</span>',
            ],
        ));

        $component = $this->hallComponent($viewer, SixHeroRoomKey::MIRACLE)
            ->call('openBattleConfirmation', 'practice', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'))
            ->assertSet('battleResult.attackerWon', true);

        $response = $this->battleResultResponse($viewer)
            ->assertSee('HP 765 / 1000')
            ->assertSee('77%')
            ->assertSee('HP 0 / 1200')
            ->assertSee('7ターン')
            ->assertSee('戦闘ログ')
            ->assertDontSee('戦闘ログを見る')
            ->assertSee('【奥義】星砕き')
            ->assertSee('【必殺技】蒼天撃')
            ->assertSee('logAttack()')
            ->assertSee('攻撃')
            ->assertSee('防御')
            ->assertSee('魔力')
            ->assertSee('精神')
            ->assertSee('敏捷')
            ->assertSee('運')
            ->assertDontSee('ATK')
            ->assertDontSee('DEF')
            ->assertDontSee('MAG')
            ->assertDontSee('SPR')
            ->assertDontSee('SPD')
            ->assertDontSee('LUK')
            ->assertSee('装備');

        $html = $response->getContent();
        $this->assertArrayNotHasKey('logs', $component->get('battleResult'));
        $this->assertStringNotContainsString('<script>opponent()', $html);
        $this->assertStringNotContainsString('<script>logAttack()', $html);
        $this->assertStringContainsString('&lt;script&gt;opponent()', $html);
        $this->assertStringContainsString(
            '<span class="battle-log-job-art-title battle-log-job-art-title--ultimate battle-log-job-art-tooltip">'
                .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">【奥義】星砕き</button>'
                .'<span class="battle-log-job-art-tooltip-panel">'
                .'<span class="battle-log-job-art-tooltip-label">戦技の効果</span>'
                .'<span class="battle-log-job-art-tooltip-description">星を砕く威力を放つ。</span>'
                .'</span></span>',
            $html,
        );
        $this->assertStringContainsString(
            '<span class="text-blue-600 font-bold text-lg">【必殺技】蒼天撃</span>',
            $html,
        );
        $this->assertStringContainsString(
            '<span class="text-blue-700 font-bold">表示確認者：'
                .'<span class="battle-log-job-art-tooltip">'
                .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">剣勢</button>'
                .'<span class="battle-log-job-art-tooltip-panel">'
                .'<span class="battle-log-job-art-tooltip-label">剣勢の獲得方法</span>'
                .'<span class="battle-log-job-art-tooltip-description">始動使用で+4。受け流し成功 +1。</span>'
                .'</span></span> +1（2/12）</span>',
            $html,
        );
        $this->assertSame(2, substr_count($html, 'data-combatant-icon'));
        $this->assertSame(2, substr_count($html, 'data-combatant-stats'));
        $this->assertSame(2, substr_count($html, 'data-combatant-equipment'));

        $combatantsPosition = strpos($html, 'data-six-hero-combatants');
        $battleLogPosition = strpos($html, 'data-battle-log');
        $this->assertNotFalse($combatantsPosition);
        $this->assertNotFalse($battleLogPosition);
        $this->assertLessThan($battleLogPosition, $combatantsPosition);
    }

    public function test_battle_result_shows_every_log_in_chronological_order_before_the_outcome_and_rank_result(): void
    {
        $season = $this->readySeason();
        $defender = $this->character('時系列確認の防衛者');
        $viewer = $this->character('時系列確認の挑戦者');
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $defender, 1);
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $viewer, 2);
        $this->bindBattle(fn (): PvPBattleResolution => $this->resolution(
            true,
            ['最初の戦闘ログ', '二番目の戦闘ログ', '最後の戦闘ログ'],
        ));

        $component = $this->hallComponent($viewer, SixHeroRoomKey::DIVINE_SPEED)
            ->call('openBattleConfirmation', 'official', $defender->id)
            ->call('executeConfirmedBattle')
            ->assertRedirect(route('six-heroes.battle-result'));

        $response = $this->battleResultResponse($viewer)
            ->assertSee('最初の戦闘ログ')
            ->assertSee('二番目の戦闘ログ')
            ->assertSee('最後の戦闘ログ')
            ->assertDontSee('戦闘ログを見る');

        $html = $response->getContent();
        $positions = [
            strpos($html, 'data-battle-log'),
            strpos($html, '最初の戦闘ログ'),
            strpos($html, '二番目の戦闘ログ'),
            strpos($html, '最後の戦闘ログ'),
            strpos($html, 'data-battle-outcome'),
            strpos($html, 'data-official-rank-result'),
        ];

        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertSame(3, substr_count($html, 'data-battle-log-line'));
        $this->assertStringNotContainsString('max-h-64', $html);
    }

    public function test_ui_layer_does_not_accept_attacker_id_or_call_the_battle_engine_directly(): void
    {
        $source = file_get_contents(app_path('Livewire/SixHeroHallScreen.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('PvPBattleService', $source);
        $this->assertStringNotContainsString('resolveBattle(', $source);
        $this->assertStringNotContainsString('executeBattle(', $source);
        $this->assertStringNotContainsString('DamageCalculator', $source);
        $this->assertStringNotContainsString('SixHeroDailyUsage', $source);

        foreach (['openBattleConfirmation', 'executeConfirmedBattle'] as $methodName) {
            $parameters = collect((new \ReflectionMethod(
                SixHeroHallScreen::class,
                $methodName,
            ))->getParameters())->pluck('name');
            $this->assertNotContains('attackerId', $parameters);
        }
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

    private function battleResultResponse(Character $viewer): TestResponse
    {
        return $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($viewer->user)
            ->get(route('six-heroes.battle-result'))
            ->assertOk();
    }

    private function bindBattle(callable $battle): void
    {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldReceive('resolveBattle')
            ->once()
            ->andReturnUsing($battle);
        $this->app->instance(PvPBattleService::class, $pvpBattleService);
    }

    private function bindNoBattle(): void
    {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldNotReceive('resolveBattle');
        $this->app->instance(PvPBattleService::class, $pvpBattleService);
    }

    /** @param array<int, mixed>|null $logs */
    private function resolution(bool $attackerWon, ?array $logs = null): PvPBattleResolution
    {
        $result = new BattleResult;
        $result->result = $attackerWon ? 'victory' : 'defeat';
        $result->logs = $logs ?? ['固定戦闘ログ1', '固定戦闘ログ2'];
        $result->turnCount = 7;

        return new PvPBattleResolution(
            result: $result,
            attackerWon: $attackerWon,
            turnCount: 7,
            attackerHp: 765,
            attackerMaxHp: 1000,
            defenderHp: -25,
            defenderMaxHp: 1200,
        );
    }

    private function readySeason(): SixHeroSeason
    {
        return $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
            true,
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

    private function freshRanking(
        SixHeroSeason $season,
        Character $character,
    ): SixHeroRanking {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('character_id', $character->id)
            ->firstOrFail();
    }

    /** @return array<int, array<string, int|string|null>> */
    private function rankingSnapshots(SixHeroSeason $season): array
    {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (SixHeroRanking $ranking): array => [
                'rank' => (int) $ranking->rank,
                'official_attack_wins' => (int) $ranking->official_attack_wins,
                'official_attack_losses' => (int) $ranking->official_attack_losses,
                'defense_wins' => (int) $ranking->defense_wins,
                'defense_losses' => (int) $ranking->defense_losses,
                'first_place_since' => $ranking->first_place_since?->toISOString(),
            ])
            ->all();
    }
}
