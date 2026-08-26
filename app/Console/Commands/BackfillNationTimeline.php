<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Services\Nation\NationLevelBenefitSettingsService;
use App\Services\Nation\NationTimelineBackfillService;
use Illuminate\Console\Command;

final class BackfillNationTimeline extends Command
{
    protected $signature = 'nation:backfill-timeline
        {--nation= : 対象国家ID}
        {--force : 国家Lv特典が非公開でも復元を実行する}';

    protected $description = '実データから国家年表の公開マイルストーンを冪等に復元する';

    public function handle(
        NationTimelineBackfillService $service,
        NationLevelBenefitSettingsService $settings,
    ): int {
        if (! $settings->enabled() && ! $this->option('force')) {
            $this->error('国家Lv特典が非公開のため実行しません。意図的に先行復元する場合だけ --force を指定してください。');

            return self::FAILURE;
        }

        $query = Nation::query()->orderBy('id');
        if ($this->option('nation') !== null) {
            $query->whereKey((int) $this->option('nation'));
        }

        $created = 0;
        $skipped = 0;
        foreach ($query->cursor() as $nation) {
            $result = $service->backfill($nation);
            $created += $result['created'];
            $skipped += $result['skipped'];
        }
        $this->info("国家年表を復元しました。追加 {$created}件 / 既存 {$skipped}件");

        return self::SUCCESS;
    }
}
