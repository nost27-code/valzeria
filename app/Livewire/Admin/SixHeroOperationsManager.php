<?php

namespace App\Livewire\Admin;

use App\Services\SixHeroOperationsService;
use App\Services\SixHeroRankingInitializationService;
use App\Services\SixHeroSeasonFinalizationService;
use App\Services\SixHeroSeasonService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

final class SixHeroOperationsManager extends Component
{
    public function boot(): void
    {
        abort_unless(
            Auth::check() && Auth::user()?->role === 'admin',
            403,
        );
    }

    public function ensureCurrentSeason(
        SixHeroSeasonService $seasonService,
    ): void {
        try {
            $season = $seasonService->currentSeason();
            session()->flash(
                'status',
                "現在Season {$season->season_key} を確認しました。",
            );
            $this->audit('ensure_current_season', 'success', [
                'season_key' => (string) $season->season_key,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            session()->flash(
                'error',
                '現在Seasonを確認できませんでした。ログを確認してください。',
            );
            $this->audit('ensure_current_season', 'failed');
        }
    }

    public function retryCurrentRankingInitialization(
        SixHeroSeasonService $seasonService,
        SixHeroRankingInitializationService $initializationService,
    ): void {
        try {
            $result = $initializationService->initialize(
                $seasonService->currentSeason(),
            );
            if ($result->waitingForPreviousFinalization) {
                $sourceKey = $result->sourceSeason?->season_key ?? '直前月';
                session()->flash(
                    'warning',
                    "{$sourceKey} に未完了公式戦があるためランキング初期化を保留しました。",
                );
                $this->audit('retry_ranking_initialization', 'waiting', [
                    'season_key' => (string) $result->season->season_key,
                    'source_season_key' => (string) $sourceKey,
                ]);

                return;
            }

            session()->flash(
                'status',
                $result->alreadyInitialized
                    ? "{$result->season->season_key} は初期化済みです。"
                    : "{$result->season->season_key} を初期化しました（引継ぎ{$result->copiedRankingCount}件）。",
            );
            $this->audit('retry_ranking_initialization', 'success', [
                'season_key' => (string) $result->season->season_key,
                'already_initialized' => $result->alreadyInitialized,
                'copied_ranking_count' => $result->copiedRankingCount,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            session()->flash(
                'error',
                'ランキング初期化を再試行できませんでした。ログを確認してください。',
            );
            $this->audit('retry_ranking_initialization', 'failed');
        }
    }

    public function retryEndedSeasonFinalization(
        SixHeroSeasonFinalizationService $finalizationService,
    ): void {
        try {
            $results = $finalizationService->finalizeEndedSeasons();
            if ($results->isEmpty()) {
                session()->flash('status', '確定対象の終了Seasonはありません。');
                $this->audit('retry_ended_season_finalization', 'no_target');

                return;
            }

            $pendingCount = $results
                ->where('pendingBattles', true)
                ->sum('pendingBattleCount');
            $finalizedCount = $results
                ->where('finalized', true)
                ->count();
            if ($pendingCount > 0) {
                session()->flash(
                    'warning',
                    "終了Season確定を再試行しました。{$finalizedCount}Season確定、未完了公式戦{$pendingCount}件は保留中です。",
                );
            } else {
                session()->flash(
                    'status',
                    "終了Season確定を再試行し、{$finalizedCount}Seasonを確認しました。",
                );
            }

            $this->audit('retry_ended_season_finalization', 'success', [
                'result_count' => $results->count(),
                'finalized_count' => $finalizedCount,
                'pending_battle_count' => (int) $pendingCount,
                'season_keys' => $results->pluck('season.season_key')->implode(','),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            session()->flash(
                'error',
                '終了Seasonの確定を再試行できませんでした。ログを確認してください。',
            );
            $this->audit('retry_ended_season_finalization', 'failed');
        }
    }

    public function render(SixHeroOperationsService $operations)
    {
        return view(
            'livewire.admin.six-hero-operations-manager',
            $operations->dashboardData(),
        )->layout('components.layouts.admin');
    }

    /** @param array<string, bool|int|string> $context */
    private function audit(
        string $action,
        string $result,
        array $context = [],
    ): void {
        Log::notice('Six Heroes admin operation executed.', [
            'admin_user_id' => Auth::id(),
            'action' => $action,
            'result' => $result,
            ...$context,
        ]);
    }
}
