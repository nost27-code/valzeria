<?php

namespace App\Console\Commands;

use App\Services\WebPushDispatchService;
use App\Services\WebPushEventService;
use Illuminate\Console\Command;

class DispatchWebPushNotifications extends Command
{
    protected $signature = 'web-push:dispatch';

    protected $description = 'Generate timed notifications and send selected character bell entries by PWA push.';

    public function handle(WebPushEventService $events, WebPushDispatchService $dispatcher): int
    {
        $generated = $events->generate();
        $result = $dispatcher->dispatch();

        $this->line(sprintf(
            'generated=%d scanned=%d sent=%d expired=%d failed=%d skipped=%d misconfigured=%d',
            $generated,
            $result['scanned'],
            $result['sent'],
            $result['expired'],
            $result['failed'],
            $result['skipped'],
            $result['misconfigured']
        ));

        return $result['failed'] > 0 || $result['misconfigured'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
