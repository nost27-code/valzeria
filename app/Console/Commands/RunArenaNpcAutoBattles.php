<?php

namespace App\Console\Commands;

use App\Services\ArenaNpcAutoBattleService;
use Illuminate\Console\Command;

class RunArenaNpcAutoBattles extends Command
{
    protected $signature = 'arena:npc-auto-battles {--battles=1}';

    protected $description = 'Run scheduled arena rank battles for NPC rankers.';

    public function handle(ArenaNpcAutoBattleService $service): int
    {
        $result = $service->runScheduled((int) $this->option('battles'));

        $this->info('Arena NPC auto battles completed: ' . (int) ($result['completed'] ?? 0));
        $this->line('Attempted: ' . (int) ($result['attempted'] ?? 0));
        if (! empty($result['reason'])) {
            $this->line('Reason: ' . $result['reason']);
        }

        return self::SUCCESS;
    }
}
