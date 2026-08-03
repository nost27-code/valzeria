<?php

namespace Tests\Unit;

use App\Livewire\ChatLog;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Livewire\Livewire;
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
        $service->expects($this->once())
            ->method('logsVersion')
            ->willReturn('version-1');

        $view = $component->render($service);

        $this->assertSame([], $view->getData()['systemLogs']);
        $this->assertSame('version-1', $component->logsVersion);
    }

    public function test_unchanged_poll_checks_the_version_without_rendering_logs_again(): void
    {
        $service = $this->createMock(PublicLogService::class);
        $service->expects($this->once())
            ->method('getRecentLogs')
            ->willReturn(collect());
        $service->expects($this->once())
            ->method('getRecentLogsVersion')
            ->willReturn('same-version');
        $service->expects($this->once())
            ->method('logsVersion')
            ->willReturn('same-version');
        $this->app->instance(PublicLogService::class, $service);

        Livewire::test(ChatLog::class)
            ->call('pollForUpdates')
            ->assertSet('logsVersion', 'same-version');
    }

    public function test_changed_poll_renders_the_latest_logs(): void
    {
        $log = new PublicLog([
            'id' => 1,
            'type' => 'chat',
            'message' => '新しい発言です',
            'importance' => 1,
        ]);
        $log->created_at = now();
        $log->updated_at = now();

        $service = $this->createMock(PublicLogService::class);
        $service->expects($this->exactly(2))
            ->method('getRecentLogs')
            ->willReturnOnConsecutiveCalls(collect(), collect([$log]));
        $service->expects($this->once())
            ->method('getRecentLogsVersion')
            ->willReturn('version-2');
        $service->expects($this->exactly(2))
            ->method('logsVersion')
            ->willReturnOnConsecutiveCalls('version-1', 'version-2');
        $this->app->instance(PublicLogService::class, $service);

        Livewire::test(ChatLog::class)
            ->call('pollForUpdates')
            ->assertSet('logsVersion', 'version-2')
            ->assertSee('新しい発言です');
    }
}
