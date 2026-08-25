<?php

namespace App\Console\Commands;

use App\Services\Nation\NationDissolutionService;
use Illuminate\Console\Command;

final class ProcessNationDissolutions extends Command
{
    protected $signature = 'nation:process-dissolutions';

    protected $description = '待機時間を過ぎた国家解散申請を冪等に論理解散する';

    public function handle(NationDissolutionService $service): int
    {
        $processed = $service->processDue();
        $this->info("processed={$processed}");

        return self::SUCCESS;
    }
}
