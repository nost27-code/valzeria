<?php

namespace App\Console\Commands;

use App\Services\EquipmentMarketService;
use Illuminate\Console\Command;

class RegenerateEquipmentMarketSnapshots extends Command
{
    protected $signature = 'equipment-market:refresh-snapshots';
    protected $description = 'Rebuild item_snapshot / display_name_snapshot for active equipment market listings from current item masters.';

    public function handle(EquipmentMarketService $service): int
    {
        $updated = $service->refreshActiveSnapshots();
        $this->info("Refreshed equipment market snapshots: {$updated} active listing(s).");

        return self::SUCCESS;
    }
}
