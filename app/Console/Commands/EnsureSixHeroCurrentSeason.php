<?php

namespace App\Console\Commands;

use App\Services\SixHeroSeasonService;
use Illuminate\Console\Command;

final class EnsureSixHeroCurrentSeason extends Command
{
    protected $signature = 'six-heroes:ensure-current-season';

    protected $description = '六英雄戦の現在月Seasonが存在することを確認する';

    public function handle(SixHeroSeasonService $seasonService): int
    {
        $season = $seasonService->currentSeason();
        $timezone = (string) config('app.timezone');

        $this->info("六英雄戦Season {$season->season_key} を確認しました。");
        $this->line(sprintf(
            '期間: %s ～ %s',
            $season->starts_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            $season->ends_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
        ));

        return self::SUCCESS;
    }
}
