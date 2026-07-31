<?php

namespace Tests\Unit;

use App\Livewire\ChatLog;
use App\Services\PublicLogService;
use Tests\TestCase;

class ChatLogRareDropLimitTest extends TestCase
{
    public function test_rare_drop_tab_requests_fifty_drop_logs_directly(): void
    {
        $component = new ChatLog();
        $component->activeTab = 'drop';

        $service = $this->createMock(PublicLogService::class);
        $service->expects($this->once())
            ->method('getRecentLogs')
            ->with(50, null, ['drop'])
            ->willReturn(collect());

        $view = $component->render($service);

        $this->assertSame([], $view->getData()['systemLogs']);
    }
}
