<?php

namespace App\Console\Commands;

use App\Services\WeeklyWinRankingService;
use Illuminate\Console\Command;

class FinalizeWeeklyWinRanking extends Command
{
    protected $signature = 'ranking:finalize-weekly-wins
        {--week-start= : 確定する週の月曜日（YYYY-MM-DD）。省略時は直前に終了した週}
        {--dry-run : 報酬を付与せず、対象人数と無償輝石合計だけを試算する}';

    protected $description = '週間勝利数番付を確定し、対象者へ無償輝石と名誉表示を付与する';

    public function handle(WeeklyWinRankingService $service): int
    {
        if (! $service->schemaReady()) {
            $this->error('週間勝利数番付のmigrationが未適用、または輝石台帳の準備ができていません。');

            return self::FAILURE;
        }

        try {
            $weekStart = trim((string) $this->option('week-start'));
            $period = $weekStart !== ''
                ? $service->periodForWeekStart($weekStart)
                : null;
            $dryRun = (bool) $this->option('dry-run');
            $result = match (true) {
                $dryRun && $period !== null => $service->previewPeriod($period),
                $dryRun => $service->previewPreviousWeek(),
                $period !== null => $service->finalizePeriod($period),
                default => $service->finalizePreviousWeek(),
            };
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['skipped'] ?? false) {
            $firstSeasonKey = $service->availability()['first_period']['key'];
            $this->warn(
                "{$result['season_key']}週は報酬開始前のため対象外です。"
                ."初回対象は{$firstSeasonKey}週です。"
            );
        } elseif ($result['preview'] ?? false) {
            $this->warn("{$result['season_key']}週の試算です。報酬は付与していません。");
        } elseif ($result['already_finalized']) {
            $this->warn("{$result['season_key']}週は確定済みです。報酬の再付与は行いませんでした。");
        } else {
            $this->info("{$result['season_key']}週の週間勝利数番付を確定しました。");
        }

        $this->line('参加者: '.number_format((int) $result['participant_count']).'人');
        $this->line('報酬対象: '.number_format((int) $result['rewarded_count']).'人');
        $this->line('無償輝石合計: '.number_format((int) $result['total_free_kiseki']).'個');

        return self::SUCCESS;
    }
}
