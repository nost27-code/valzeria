<?php

namespace Tests\Unit;

use App\Livewire\ChatLog;
use App\Services\PublicLogService;
use Tests\TestCase;

class ChatLogDefaultLimitTest extends TestCase
{
    public function test_home_chat_defaults_to_fifty_logs(): void
    {
        $component = new ChatLog();

        $this->assertSame(50, $component->logLimit);
    }

    public function test_all_tab_requests_only_the_fifty_visible_logs(): void
    {
        $component = new ChatLog();
        $component->allTabVisibility = [
            'chat' => true,
            'admin_info' => true,
            'drop' => true,
            'growth' => true,
            'discovery' => true,
            'arena' => true,
            'valmon' => true,
            'system' => true,
            'newcomer' => false,
        ];

        $service = $this->createMock(PublicLogService::class);
        $service->expects($this->once())
            ->method('getRecentLogs')
            ->with(50, null, null, ['private', 'admin_private', 'admin_private_reply', 'admin_reply_resolved'], false)
            ->willReturn(collect());

        $view = $component->render($service);

        $this->assertSame([], $view->getData()['systemLogs']);
    }
}
