<?php

namespace Tests\Unit;

use App\Livewire\ChatLog;
use App\Models\Character;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ChatDrawerPresentationTest extends TestCase
{
    public function test_public_logs_are_presented_oldest_to_newest_for_bottom_latest_chat(): void
    {
        $character = new Character([
            'name' => '発言者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        $character->id = 10;

        $older = $this->chatLog(1, '古い発言', CarbonImmutable::parse('2026-09-22 10:00:00'), $character);
        $newer = $this->chatLog(2, '新しい発言', CarbonImmutable::parse('2026-09-22 10:01:00'), $character);

        $service = $this->createMock(PublicLogService::class);
        $service->method('getRecentLogs')->willReturn(collect([$newer, $older]));

        $component = new ChatLog();
        $component->drawer = true;
        $component->activeTab = 'chat';
        $view = $component->render($service);
        $logs = $view->getData()['systemLogs'];

        $this->assertSame([1, 2], array_column($logs, 'id'));
        $this->assertSame(['古い発言', '新しい発言'], array_column($logs, 'message'));
        $this->assertTrue($logs[0]['is_player_message']);
        $this->assertSame('発言者', $logs[0]['author_name']);
        $this->assertStringContainsString('chara_001.webp', $logs[0]['avatar_url']);
    }

    public function test_home_layout_restores_inline_chat_and_keeps_the_drawer_chat(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $header = file_get_contents(resource_path('views/livewire/city-header.blade.php'));
        $chat = file_get_contents(resource_path('views/livewire/chat-log.blade.php'));

        $this->assertIsString($layout);
        $this->assertIsString($header);
        $this->assertIsString($chat);
        $this->assertSame(2, substr_count($layout, '<livewire:chat-log'));
        $this->assertStringContainsString('<livewire:chat-log :key="\'home-inline-chat\'" />', $layout);
        $this->assertStringContainsString('<livewire:chat-log :drawer="true" :key="\'home-drawer-chat\'" />', $layout);
        $this->assertStringContainsString(':show-chat-launcher="true"', $layout);
        $this->assertStringContainsString("open-chat-drawer", $header);
        $this->assertStringContainsString('w-[80vw]', $chat);
        $this->assertStringContainsString('data-chat-log-scroller', $chat);
        $this->assertStringContainsString('scrollToBottom(false)', $chat);
        $this->assertStringContainsString("drawerTabStorageKey: 'valzeria.chat.drawer.active-tab'", $chat);
        $this->assertStringContainsString('restoreDrawerTab()', $chat);
        $this->assertStringContainsString('@click="rememberDrawerTab(\'chat\')" wire:click="setTab(\'chat\')"', $chat);
        $this->assertStringContainsString('@click="rememberDrawerTab(\'nation\')" wire:click="setTab(\'nation\')"', $chat);
        $this->assertStringContainsString('@chat-drawer-tab-changed.window="rememberDrawerTab($event.detail.tab)"', $chat);
        $this->assertStringContainsString("dispatch('chat-drawer-tab-changed', tab: \$tab)", file_get_contents(app_path('Livewire/ChatLog.php')));
        $this->assertStringContainsString('data-chat-drawer-edge-swipe', $chat);
        $this->assertStringContainsString('style="display: none; touch-action: pan-y;"', $chat);
        $this->assertStringContainsString('fromEdgeHandle || x >= window.innerWidth - 48', $chat);
        $this->assertStringContainsString('isHorizontalSwipe && deltaX < -48', $chat);
        $this->assertStringContainsString('data-chat-line-presentation', $chat);
        $this->assertStringContainsString('data-chat-inline-presentation', $chat);
        $this->assertGreaterThanOrEqual(2, substr_count($chat, "src=\"{{ \$log['avatar_url'] }}\""));
        $inlinePresentation = substr($chat, strpos($chat, 'data-chat-inline-presentation'));
        $this->assertStringNotContainsString("src=\"{{ \$log['avatar_url'] }}\"", $inlinePresentation);
    }

    public function test_inline_chat_keeps_the_original_newest_first_order(): void
    {
        $character = new Character(['name' => '発言者', 'icon_path' => '/images/characters/chara_001.webp']);
        $character->id = 10;
        $older = $this->chatLog(1, '古い発言', CarbonImmutable::parse('2026-09-22 10:00:00'), $character);
        $newer = $this->chatLog(2, '新しい発言', CarbonImmutable::parse('2026-09-22 10:01:00'), $character);

        $service = $this->createMock(PublicLogService::class);
        $service->method('getRecentLogs')->willReturn(collect([$newer, $older]));

        $component = new ChatLog();
        $component->activeTab = 'chat';
        $logs = $component->render($service)->getData()['systemLogs'];

        $this->assertSame([2, 1], array_column($logs, 'id'));
    }

    public function test_system_logs_also_receive_an_icon_and_speaker_name(): void
    {
        $createdAt = CarbonImmutable::parse('2026-09-22 10:02:00');
        $log = new PublicLog([
            'type' => 'system',
            'message' => '世界からのお知らせ',
        ]);
        $log->id = 3;
        $log->created_at = $createdAt;
        $log->updated_at = $createdAt;
        $log->setRelation('character', null);
        $log->setRelation('receiver', null);

        $service = $this->createMock(PublicLogService::class);
        $service->method('getRecentLogs')->willReturn(collect([$log]));

        $component = new ChatLog();
        $component->activeTab = 'system';
        $logs = $component->render($service)->getData()['systemLogs'];

        $this->assertFalse($logs[0]['is_player_message']);
        $this->assertSame('ヴァルゼリア', $logs[0]['author_name']);
        $this->assertStringContainsString('icon_001.webp', $logs[0]['avatar_url']);
    }

    private function chatLog(
        int $id,
        string $message,
        CarbonImmutable $createdAt,
        Character $character,
    ): PublicLog {
        $log = new PublicLog([
            'type' => 'chat',
            'message' => $message,
            'character_id' => $character->id,
        ]);
        $log->id = $id;
        $log->created_at = $createdAt;
        $log->updated_at = $createdAt;
        $log->setRelation('character', $character);
        $log->setRelation('receiver', null);

        return $log;
    }
}
