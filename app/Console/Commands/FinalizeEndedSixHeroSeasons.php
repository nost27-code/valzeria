<?php

namespace App\Console\Commands;

use App\Services\SixHeroSeasonFinalizationResult;
use App\Services\SixHeroSeasonFinalizationService;
use Illuminate\Console\Command;
use LogicException;

final class FinalizeEndedSixHeroSeasons extends Command
{
    protected $signature = 'six-heroes:finalize-ended-seasons';

    protected $description = '終了した六英雄戦Seasonの英雄・空位を確定する';

    public function handle(SixHeroSeasonFinalizationService $service): int
    {
        try {
            $results = $service->finalizeEndedSeasons();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($results->isEmpty()) {
            $this->info('確定対象の六英雄戦Seasonはありません。');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->reportResult($result);
        }

        return self::SUCCESS;
    }

    private function reportResult(SixHeroSeasonFinalizationResult $result): void
    {
        $key = $result->season->season_key;
        if ($result->pendingBattles) {
            $this->warn(
                "{$key}: pending battle {$result->pendingBattleCount}件のため保留しました。",
            );

            return;
        }

        if ($result->alreadyFinalized) {
            $this->warn("{$key}: 確定済みです。");

            return;
        }

        $vacantCount = $result->champions
            ->filter(fn ($champion): bool => $champion->is_vacant)
            ->count();
        $heroCount = $result->champions->count() - $vacantCount;
        $this->info(
            "{$key}: 6部屋を確定しました（英雄{$heroCount} / 空位{$vacantCount}）。",
        );
    }
}
