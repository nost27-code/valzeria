<?php

namespace App\Console\Commands;

use App\Services\SixHeroHealthCheckItem;
use App\Services\SixHeroOperationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;

final class CheckSixHeroHealth extends Command
{
    protected $signature = 'six-heroes:health-check
        {--json : 診断結果をJSONで出力する}';

    protected $description = '六英雄戦の運用状態をread-onlyで診断する';

    /** @throws JsonException */
    public function handle(SixHeroOperationsService $operations): int
    {
        $report = $operations->healthReport();
        $counts = $report->statusCounts();
        $logContext = [
            'overall_status' => $report->overallStatus(),
            'pass_count' => $counts[SixHeroHealthCheckItem::STATUS_PASS],
            'warning_count' => $counts[SixHeroHealthCheckItem::STATUS_WARNING],
            'fail_count' => $counts[SixHeroHealthCheckItem::STATUS_FAIL],
            'failed_checks' => collect($report->items)
                ->where('status', SixHeroHealthCheckItem::STATUS_FAIL)
                ->pluck('key')
                ->values()
                ->all(),
        ];

        if ($report->hasFailures()) {
            Log::error('Six Heroes health check failed.', $logContext);
        } elseif ($report->hasWarnings()) {
            Log::warning('Six Heroes health check has warnings.', $logContext);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));

            return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->info('Six Heroes Health Check');
        $this->line('Checked at: '.$report->checkedAt->toIso8601String());
        $this->newLine();
        foreach ($report->items as $item) {
            $status = match ($item->status) {
                SixHeroHealthCheckItem::STATUS_PASS => 'PASS',
                SixHeroHealthCheckItem::STATUS_WARNING => 'WARNING',
                default => 'FAIL',
            };
            $this->line("[{$status}] {$item->label}: {$item->message}");
        }

        $this->newLine();
        $this->line(sprintf(
            'PASS %d / WARNING %d / FAIL %d',
            $counts[SixHeroHealthCheckItem::STATUS_PASS],
            $counts[SixHeroHealthCheckItem::STATUS_WARNING],
            $counts[SixHeroHealthCheckItem::STATUS_FAIL],
        ));

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
