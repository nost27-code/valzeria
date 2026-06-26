<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Services\PublicLogService;
use Livewire\Component;

class AdminChatManager extends Component
{
    public string $message = '';

    public function sendMessage(PublicLogService $logService): void
    {
        $this->validate([
            'message' => ['required', 'string', 'max:160'],
        ]);

        $logService->addLog('admin', trim($this->message), null, 4);

        $this->message = '';
        session()->flash('status', '管理人メッセージを全体チャットへ送信しました。');
    }

    public function render(PublicLogService $logService)
    {
        $onlineWindowMinutes = max(1, (int) config('services.pochi_game_portal.online_window_minutes', 5));
        $onlineCharacters = Character::query()
            ->where('last_seen_at', '>=', now()->subMinutes($onlineWindowMinutes))
            ->orderByDesc('last_seen_at')
            ->limit(80)
            ->get(['id', 'name', 'last_seen_at']);

        $logs = $logService->getRecentLogs(40)
            ->filter(fn ($log) => $log->type !== 'private')
            ->take(25)
            ->map(fn ($log) => [
                'type' => $log->type,
                'message' => $log->message,
                'name' => $log->character?->name,
                'time' => $log->created_at ? $log->created_at->format('H:i') : now()->format('H:i'),
            ])
            ->values();

        return view('livewire.admin.admin-chat-manager', [
            'onlineCharacters' => $onlineCharacters,
            'onlineWindowMinutes' => $onlineWindowMinutes,
            'logs' => $logs,
        ])->layout('components.layouts.admin');
    }
}
