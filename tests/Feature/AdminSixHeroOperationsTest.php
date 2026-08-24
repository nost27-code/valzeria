<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\Admin\SixHeroOperationsManager;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminSixHeroOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tokyo',
            'features.six_hero_ui_enabled' => false,
            'six_heroes.operations.expected_database_product' => 'sqlite',
            'six_heroes.operations.minimum_database_version' => '3.0.0',
            'six_heroes.operations.stale_battle_minutes' => 30,
            'six_heroes.operations.failed_battle_window_hours' => 24,
            'six_heroes.operations.battle_list_limit' => 20,
            'six_heroes.champion_recording_starts_from_season' => '2026-01',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_open_operations_page_while_non_admin_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->get(route('admin.six-heroes'))
            ->assertOk()
            ->assertSee('六英雄戦 運用状況')
            ->assertSee('現在Seasonを再確認')
            ->assertSee('Ranking初期化を再試行')
            ->assertSee('終了Season確定を再試行')
            ->assertSee(route('admin.six-heroes'), escape: false);

        $this->actingAs($player)
            ->get(route('admin.six-heroes'))
            ->assertRedirect('/admin/login');

        Livewire::actingAs($player)
            ->test(SixHeroOperationsManager::class)
            ->assertForbidden();
    }

    public function test_ensure_current_season_only_creates_or_confirms_the_season(): void
    {
        Log::spy();
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(SixHeroOperationsManager::class)
            ->call('ensureCurrentSeason')
            ->assertSee('現在Season 2026-09 を確認しました。');

        $this->assertDatabaseHas('six_hero_seasons', [
            'season_key' => '2026-09',
            'ranking_initialized_at' => null,
        ]);
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertDatabaseCount('six_hero_champions', 0);
        Log::shouldHaveReceived('notice')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Six Heroes admin operation executed.'
                && $context['admin_user_id'] === $admin->id
                && $context['action'] === 'ensure_current_season'
                && $context['result'] === 'success');
    }

    public function test_ranking_initialization_retry_respects_pending_previous_battle_without_side_effects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $previous = $this->season('2026-08');
        $current = $this->season('2026-09');
        $this->pendingBattle($previous);

        $before = $this->competitionTableCounts();

        Livewire::actingAs($admin)
            ->test(SixHeroOperationsManager::class)
            ->call('retryCurrentRankingInitialization')
            ->assertSee('2026-08 に未完了公式戦があるためランキング初期化を保留しました。');

        $this->assertNull($previous->fresh()->finalized_at);
        $this->assertNull($current->fresh()->ranking_initialized_at);
        $this->assertSame($before, $this->competitionTableCounts());
    }

    public function test_ended_season_finalization_retry_is_safe_and_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $previous = $this->season('2026-08');

        Livewire::actingAs($admin)
            ->test(SixHeroOperationsManager::class)
            ->call('retryEndedSeasonFinalization')
            ->assertSee('終了Season確定を再試行し、1Seasonを確認しました。');

        $this->assertNotNull($previous->fresh()->finalized_at);
        $this->assertSame(6, SixHeroChampion::query()
            ->where('season_id', $previous->id)
            ->count());

        Livewire::actingAs($admin)
            ->test(SixHeroOperationsManager::class)
            ->call('retryEndedSeasonFinalization')
            ->assertSee('確定対象の終了Seasonはありません。');

        $this->assertSame(6, SixHeroChampion::query()
            ->where('season_id', $previous->id)
            ->count());
    }

    public function test_finalization_retry_never_forces_past_pending_battles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $previous = $this->season('2026-08');
        $pending = $this->pendingBattle($previous);

        Livewire::actingAs($admin)
            ->test(SixHeroOperationsManager::class)
            ->call('retryEndedSeasonFinalization')
            ->assertSee('未完了公式戦1件は保留中です。');

        $this->assertNull($previous->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 0);
        $this->assertDatabaseHas('six_hero_battle_logs', [
            'id' => $pending->id,
            'status' => SixHeroBattleLog::STATUS_STARTED,
        ]);
    }

    public function test_operations_view_exposes_only_the_three_safe_write_actions(): void
    {
        $view = file_get_contents(resource_path(
            'views/livewire/admin/six-hero-operations-manager.blade.php',
        ));

        preg_match_all('/wire:click="([^"]+)"/', $view, $matches);

        $this->assertSame([
            'ensureCurrentSeason',
            'retryCurrentRankingInitialization',
            'retryEndedSeasonFinalization',
        ], $matches[1]);
        $this->assertStringNotContainsString('forceFinalize', $view);
        $this->assertStringNotContainsString('deleteBattle', $view);
        $this->assertStringNotContainsString('updateRanking', $view);
        $this->assertStringNotContainsString('toggleFeature', $view);
    }

    private function season(string $key): SixHeroSeason
    {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo');

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth(),
            'finalized_at' => null,
            'ranking_initialized_at' => null,
        ]);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    private function pendingBattle(SixHeroSeason $season): SixHeroBattleLog
    {
        $attacker = $this->character('Phase7管理攻撃者');
        $defender = $this->character('Phase7管理防衛者');

        return SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_STARTED,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => $season->ends_at->copy()->subMinute(),
        ]);
    }

    /** @return array<string, int> */
    private function competitionTableCounts(): array
    {
        return [
            'seasons' => SixHeroSeason::query()->count(),
            'rankings' => SixHeroRanking::query()->count(),
            'daily_usages' => SixHeroDailyUsage::query()->count(),
            'battle_logs' => SixHeroBattleLog::query()->count(),
            'champions' => SixHeroChampion::query()->count(),
        ];
    }
}
