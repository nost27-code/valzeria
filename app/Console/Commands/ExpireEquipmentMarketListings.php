<?php

namespace App\Console\Commands;

use App\Services\EquipmentMarketService;
use Illuminate\Console\Command;

class ExpireEquipmentMarketListings extends Command
{
    protected $signature = 'equipment-market:expire';
    protected $description = 'Expire equipment market listings and return weapons to their sellers.';

    public function handle(EquipmentMarketService $service): int
    {
        $this->info('Expired equipment market listings: ' . $service->expireListings());
        return self::SUCCESS;
    }
}
