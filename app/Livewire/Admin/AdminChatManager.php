<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AdminChatManager extends Component
{
    private const INITIAL_LOG_LIMIT = 80;
    private const LOG_LIMIT_STEP = 80;
    private const MAX_LOG_LIMIT = 500;

    /** 冒険者の発言だけを抽出するときに選べる件数 */
    public const EXTRACT_LIMIT_OPTIONS = [200, 500, 1000, 2000, 5000];
    private const DEFAULT_EXTRACT_LIMIT = 1000;

    public string $message = '';
    public string $messageType = 'admin';
    public int $logLimit = self::INITIAL_LOG_LIMIT;
    public int $extractLimit = self::DEFAULT_EXTRACT_LIMIT;

    public function boot(): void
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403);
    }

    public function loadMoreLogs(): void
    {
        $this->logLimit = min(self::MAX_LOG_LIMIT, $this->logLimit + self::LOG_LIMIT_STEP);
    }

    public function updatedExtractLimit(): void
    {
        $this->extractLimit = $this->normalizedExtractLimit();
    }

    /**
     * 下部チャットの冒険者発言（緑色の全体チャット）だけをまとめてコピーする。
     * AIへ貼り付けて改善案を洗い出す用途のため、古い順のテキストとして渡す。
     */
    public function copyPlayerChat(): void
    {
        $limit = $this->normalizedExtractLimit();
        $this->extractLimit = $limit;

        $logs = $this->playerChatLogs($limit);

        if ($logs->isEmpty()) {
            session()->flash('error', 'コピーできる冒険者の発言がありません。');
            return;
        }

        $this->dispatch(
            'player-chat-extracted',
            text: $this->formatPlayerChat($logs),
            count: $logs->count(),
        );
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
            'extractLimitOptions' => self::EXTRACT_LIMIT_OPTIONS,
        ])->layout('components.layouts.admin');
    }

    private function normalizedExtractLimit(): int
    {
        $limit = (int) $this->extractLimit;

        return in_array($limit, self::EXTRACT_LIMIT_OPTIONS, true) ? $limit : self::DEFAULT_EXTRACT_LIMIT;
    }

    /**
     * 冒険者本人の全体チャット発言だけを古い順で取得する。
     * 管理人・お知らせ・システムログ、個人チャット、管理・テスト用アカウントは含めない。
     *
     * @return Collection<int, PublicLog>
     */
    private function playerChatLogs(int $limit): Collection
    {
        return PublicLog::query()
            ->with('character:id,name')
            ->where('type', 'chat')
            ->whereNotNull('character_id')
            ->whereDoesntHave('character', fn ($characterQuery) => $characterQuery->excludedFromPublicLogs())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * @param Collection<int, PublicLog> $logs
     */
    private function formatPlayerChat(Collection $logs): string
    {
        $first = $logs->first()?->created_at;
        $last = $logs->last()?->created_at;

        $lines = [
            '# ヴァルゼリアの冒険者 全体チャット 冒険者発言ログ',
            '# 抽出日時: ' . now()->format('Y-m-d H:i') . ' / 件数: ' . number_format($logs->count()) . '件',
            '# 対象期間: ' . ($first ? $first->format('Y-m-d H:i') : '不明') . ' 〜 ' . ($last ? $last->format('Y-m-d H:i') : '不明'),
            '# 並び順: 古い発言→新しい発言 / 管理人・お知らせ・システムログ・個人チャットは含みません',
            '',
        ];

        foreach ($logs as $log) {
            $lines[] = ($log->created_at ? $log->created_at->format('Y-m-d H:i') : '不明')
                . ' 【' . ($log->character?->name ?? '名無し') . '】'
                . $this->singleLineMessage((string) $log->message);
        }

        return implode("\n", $lines);
    }

    /**
     * 1発言を1行に収めるため、改行や連続空白を1つの空白へ潰す。
     */
    private function singleLineMessage(string $message): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $message));
    }
}
