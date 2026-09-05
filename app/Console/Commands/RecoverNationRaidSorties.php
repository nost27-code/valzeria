<?php

namespace App\Console\Commands;

use App\Services\Nation\Raid\NationRaidSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverNationRaidSorties extends Command
{
    protected $signature = 'nation-raid:recover-sorties';
    protected $description = '確定期限を過ぎたレイド出撃の探索力と回数を一度だけ返却する';

    public function handle(NationRaidSettlementService $settlement): int
    {
        if (! Schema::hasTable('nation_raid_battle_results')) {
            return self::SUCCESS;
        }
        $result = $settlement->recoverExpired();
        $this->info('返却 '.$result['refunded'].' / 回収保留 '.$result['failed']);

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
