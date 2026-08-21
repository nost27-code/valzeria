<?php

namespace App\Console\Commands;

use App\Services\SixHeroRankingInitializationService;
use App\Services\SixHeroSeasonService;
use Illuminate\Console\Command;
use LogicException;

final class InitializeSixHeroCurrentRankings extends Command
{
    protected $signature = 'six-heroes:initialize-current-rankings';

    protected $description = '六英雄戦の現在月ランキングを直前月から初期化する';

    public function handle(
        SixHeroSeasonService $seasonService,
        SixHeroRankingInitializationService $initializationService,
    ): int {
        try {
            $result = $initializationService->initialize(
                $seasonService->currentSeason(),
            );
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->waitingForPreviousFinalization) {
            $sourceKey = $result->sourceSeason?->season_key ?? '直前月';
            $this->warn("{$sourceKey} に未完了公式戦があるため");
            $this->warn("{$result->season->season_key} のランキング初期化を保留しました。");

            return self::SUCCESS;
        }

        if ($result->alreadyInitialized) {
            $this->info("{$result->season->season_key} は既に初期化済みです。");

            return self::SUCCESS;
        }

        $this->info(
            "六英雄戦 {$result->season->season_key} ランキングを初期化しました。",
        );
        $this->line('引継ぎ元: '.($result->sourceSeason?->season_key ?? 'なし'));
        $this->line("引継ぎ: {$result->copiedRankingCount}件");

        return self::SUCCESS;
    }
}
