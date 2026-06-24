<?php

namespace App\Console\Commands;

use App\Services\MarketService;
use Illuminate\Console\Command;

class ExpireMarketListings extends Command
{
    protected $signature = 'market:expire-listings';

    protected $description = 'Expire active market listings and return remaining materials to sellers.';

    public function handle(MarketService $marketService): int
    {
        $count = $marketService->expireListings();
        $this->info("Expired market listings: {$count}");

        return self::SUCCESS;
    }
}
