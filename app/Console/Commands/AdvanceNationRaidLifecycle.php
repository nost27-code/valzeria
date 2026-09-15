<?php

namespace App\Console\Commands;

use App\Services\Nation\Raid\NationRaidLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class AdvanceNationRaidLifecycle extends Command
{
    protected $signature = 'nation-raid:lifecycle';
    protected $description = 'レイドの兵站準備・開始・受付終了・終了30分後の自動確定を処理する';

    public function handle(NationRaidLifecycleService $service): int
    {
        if (! Schema::hasTable('nation_raid_events')) {
            return self::SUCCESS;
        }
        $counts = $service->advanceDue();
        $this->info('兵站開始 '.$counts['preparations'].' / 開始 '.$counts['started'].' / 終了処理へ '.$counts['closing']
            .' / 戦果確定 '.$counts['finalized'].' / 確定待ち '.$counts['finalization_waiting'].' / 公開待ち '.$counts['deferred']
            .' / 未開催のまま期限超過 '.$counts['missed'].' / 要確認 '.$counts['failed']);
        return $counts['failed'] === 0 && $counts['missed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
