<?php

namespace App\Console\Commands;

use App\Services\NpcProcurementRequestService;
use Illuminate\Console\Command;

class ExpireNpcProcurementRequests extends Command
{
    protected $signature = 'npc-requests:expire';

    protected $description = 'Expire active NPC procurement requests.';

    public function handle(NpcProcurementRequestService $service): int
    {
        $count = $service->expireRequests();
        $this->info("Expired NPC procurement requests: {$count}");

        return self::SUCCESS;
    }
}
