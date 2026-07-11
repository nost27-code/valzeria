<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Services\PublicLogService;
use Livewire\Component;

class AdminChatManager extends Component
{
    private const INITIAL_LOG_LIMIT = 80;
    private const LOG_LIMIT_STEP = 80;
    private const MAX_LOG_LIMIT = 500;

    public string $message = '';
    public string $messageType = 'admin';
    public int $logLimit = self::INITIAL_LOG_LIMIT;

    public function loadMoreLogs(): void
    {
        $this->logLimit = min(self::MAX_LOG_LIMIT, $this->logLimit + self::LOG_LIMIT_STEP);
    }

    public function sendMessage(PublicLogService $logService): void
    {
        $this->validate([
            'message' => ['required', 'string', 'max:160'],
            'messageType' => ['required', 'in:admin,notice'],
        ]);

        $type = $this->messageType === 'notice' ? 'notice' : 'admin';
        $logService->addLog($type, trim($this->message), null, $type === 'admin' ? 4 : 3);

        $this->message = '';
        $label = $type === 'notice' ? 'お知らせ' : '管理人メッセージ';
        session()->flash('status', $label . 'を全体チャットへ送信しました。');
    }

    public function render(PublicLogService $logService)
    {
        $onlineWindowMinutes = max(1, (int) config('services.pochi_game_portal.online_window_minutes', 5));
        $onlineCharacters = Character::visibleToPublic()
            ->where('last_seen_at', '>=', now()->subMinutes($onlineWindowMinutes))
            ->orderByDesc('last_seen_at')
            ->limit(80)
            ->get(['id', 'name', 'last_seen_at']);

        $fetchLimit = min(self::MAX_LOG_LIMIT * 2, max($this->logLimit * 2, self::INITIAL_LOG_LIMIT));
        $logs = $logService->getRecentLogs($fetchLimit)
            ->filter(fn ($log) => $log->type !== 'private')
            ->take($this->logLimit)
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
            'logLimit' => $this->logLimit,
            'canLoadMoreLogs' => $logs->count() >= $this->logLimit && $this->logLimit < self::MAX_LOG_LIMIT,
        ])->layout('components.layouts.admin');
    }
}
