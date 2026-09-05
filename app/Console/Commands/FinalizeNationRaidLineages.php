<?php

namespace App\Console\Commands;

use App\Services\Nation\Raid\NationRaidDailyLineageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class FinalizeNationRaidLineages extends Command
{
    protected $signature = 'nation-raid:finalize-lineages';
    protected $description = '前日の確定済み出撃編成から国家対抗レイドの対抗系譜を確定する';

    public function handle(NationRaidDailyLineageService $service): int
    {
        if (! Schema::hasTable('nation_raid_daily_lineage_snapshots')) {
            return self::SUCCESS;
        }
        $counts = $service->finalizeDue();
        $this->info('系譜確定 '.$counts['finalized'].' / 精算待ち '.$counts['waiting'].' / 要確認 '.$counts['failed']);
        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
