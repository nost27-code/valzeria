<?php

namespace App\Console\Commands;

use App\Services\SixHeroHealthCheckItem;
use App\Services\SixHeroOperationsService;
use Illuminate\Console\Command;
use JsonException;

final class CheckSixHeroRelease extends Command
{
    protected $signature = 'six-heroes:release-check
        {--json : 公開前診断結果をJSONで出力する}';

    protected $description = '六英雄戦のfeature flagをONにする前のread-only preflightを行う';

    /** @throws JsonException */
    public function handle(SixHeroOperationsService $operations): int
    {
        $report = $operations->healthReport();
        $ready = ! $report->hasFailures();

        if ((bool) $this->option('json')) {
            $payload = $report->toArray();
            $payload['release_status'] = $ready ? 'ready' : 'not_ready';
            $this->line(json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info('Six Heroes Release Check');
        foreach ($report->items as $item) {
            $status = match ($item->status) {
                SixHeroHealthCheckItem::STATUS_PASS => 'PASS',
                SixHeroHealthCheckItem::STATUS_WARNING => 'WARNING',
                default => 'FAIL',
            };
            $this->line("[{$status}] {$item->label}: {$item->message}");
        }

        $this->newLine();
        if ($ready) {
            $this->info('READY — feature flagをONにする前提条件を満たしています。');

            return self::SUCCESS;
        }

        $this->error('NOT READY — FAIL項目を解消してから再実行してください。');

        return self::FAILURE;
    }
}
