<?php

namespace App\Console\Commands;

use App\Services\NpcMarketListingService;
use Illuminate\Console\Command;

class GenerateNpcMarketListings extends Command
{
    protected $signature = 'market:generate-npc-listings {--limit=6}';

    protected $description = 'Generate market listings from NPC material stocks.';

    public function handle(NpcMarketListingService $service): int
    {
        $result = $service->generateListings((int) $this->option('limit'));

        $this->info('NPC market listings generated: ' . (int) ($result['generated'] ?? 0));

        return self::SUCCESS;
    }
}
