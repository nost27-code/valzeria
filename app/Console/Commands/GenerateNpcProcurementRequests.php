<?php

namespace App\Console\Commands;

use App\Services\NpcProcurementRequestGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateNpcProcurementRequests extends Command
{
    protected $signature = 'npc-requests:generate {--force} {--date=}';

    protected $description = 'Generate NPC procurement requests from templates.';

    public function handle(NpcProcurementRequestGenerationService $service): int
    {
        $dateOption = $this->option('date');
        $date = $dateOption ? Carbon::parse((string) $dateOption) : now();

        $result = $service->generateDailyRequests(
            date: $date,
            force: (bool) $this->option('force')
        );

        $this->info('NPC requests generated: ' . (int) ($result['generated'] ?? 0));
        $this->line('Expired: ' . (int) ($result['expired'] ?? 0));
        if (! empty($result['reason'])) {
            $this->line('Reason: ' . $result['reason']);
        }

        return self::SUCCESS;
    }
}
