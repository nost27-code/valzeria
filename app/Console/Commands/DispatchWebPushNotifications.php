<?php

namespace App\Console\Commands;

use App\Services\WebPushDispatchService;
use Illuminate\Console\Command;

class DispatchWebPushNotifications extends Command
{
    protected $signature = 'web-push:dispatch';

    protected $description = 'Send a generic PWA push notification for new character bell entries.';

    public function handle(WebPushDispatchService $dispatcher): int
    {
        $result = $dispatcher->dispatch();

        $this->line(sprintf(
            'scanned=%d sent=%d expired=%d failed=%d skipped=%d misconfigured=%d',
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
