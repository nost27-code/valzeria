<?php

namespace App\Console\Commands;

use App\Services\Nation\NationWarLifecycleService;
use Illuminate\Console\Command;

final class RunNationWarLifecycle extends Command
{
    protected $signature = 'nation-war:lifecycle';
    protected $description = '国家戦の開戦・終戦判定・再建完了を冪等に処理する';
    public function handle(NationWarLifecycleService $service): int
    {
        $result = $service->run();
        $this->info("activated={$result['activated']} resolved={$result['resolved']} rebuilt={$result['rebuilt']}");
        return self::SUCCESS;
    }
}
