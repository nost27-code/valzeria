<?php

namespace App\Console\Commands;

use App\Services\Nation\Raid\NationRaidLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class AdvanceNationRaidLifecycle extends Command
{
    protected $signature = 'nation-raid:lifecycle';
    protected $description = '予約済みレイドの開始と期間終了を処理する（報酬・最終確定なし）';

    public function handle(NationRaidLifecycleService $service): int
    {
        if (! Schema::hasTable('nation_raid_events')) {
            return self::SUCCESS;
        }
        $counts = $service->advanceDue();
        $this->info('開始 '.$counts['started'].' / 終了処理へ '.$counts['closing'].' / 公開待ち '.$counts['deferred']
            .' / 未開催のまま期限超過 '.$counts['missed'].' / 要確認 '.$counts['failed']);
        return $counts['failed'] === 0 && $counts['missed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
