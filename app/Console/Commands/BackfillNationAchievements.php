<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Services\Nation\NationAchievementBackfillService;
use App\Services\Nation\NationLevelBenefitSettingsService;
use Illuminate\Console\Command;

final class BackfillNationAchievements extends Command
{
    protected $signature = 'nation:backfill-achievements
        {--nation= : 対象国家ID}
        {--force : 国家Lv特典が非公開でも復元を実行する}';

    protected $description = '実データから既存国家の恒久実績を冪等に復元する';

    public function handle(
        NationAchievementBackfillService $service,
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
        foreach ($query->cursor() as $nation) {
            $created += $service->backfill($nation)['created'];
        }
        $this->info("国家実績を復元しました。追加 {$created}件");

        return self::SUCCESS;
    }
}
