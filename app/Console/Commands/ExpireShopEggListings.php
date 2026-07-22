<?php

namespace App\Console\Commands;

use App\Services\ShopEggListingService;
use Illuminate\Console\Command;

class ExpireShopEggListings extends Command
{
    protected $signature = 'shops:expire-eggs';
    protected $description = '期限切れのヴァルモン卵出品を終了する';

    public function handle(ShopEggListingService $service): int
    {
        $this->info('Expired: ' . $service->expireListings());
        return self::SUCCESS;
    }
}
