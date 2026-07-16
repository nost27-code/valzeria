<?php

namespace App\Livewire;

use App\Models\Character;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatLog extends Component
{
    public string $activeTab = 'all';
    public bool $isExpanded = false;
    public int $logLimit = 15;
    public array $allTabVisibility = [];

    const LOG_STEP = 50;
    const LOG_MAX  = 500;
    private const ALL_TAB_FILTERS = [
        'chat' => [
            'label' => 'チャット',
            'description' => '冒険者の全体発言',
            'types' => ['chat', 'guild'],
            'fallback_tab' => 'チャット',
            'default' => true,
        ],
        'admin_info' => [
            'label' => '管理人・お知らせ',
            'description' => '管理人投稿と運営お知らせ',
            'types' => ['admin', 'notice', 'info'],
            'fallback_tab' => 'お知らせ',
            'default' => true,
        ],
        'drop' => [
            'label' => 'レアドロップ',
            'description' => '装備や特別な入手ログ',
            'types' => ['drop'],
            'fallback_tab' => 'レアドロップ',
            'default' => true,
        ],
        'growth' => [
            'label' => '成長・転職',
            'description' => 'レベル到達や転職ログ',
            'types' => ['growth', 'job', 'job_change'],
            'fallback_tab' => 'システム',
            'default' => true,
        ],
        'discovery' => [
            'label' => '発見・進行',
            'description' => '街道、エリア、亜域の発見',
            'types' => ['area', 'sub_area'],
            'fallback_tab' => 'システム',
            'default' => true,
        ],
        'arena' => [
            'label' => '闘技場',
            'description' => '順位変動や決闘ログ',
            'types' => ['arena', 'duel'],
            'fallback_tab' => 'システム',
            'default' => true,
        ],
        'valmon' => [
            'label' => 'ヴァルモン',
            'description' => '卵や仲間に関するログ',
            'types' => ['valmon'],
            'fallback_tab' => 'システム',
            'default' => true,
        ],
        'system' => [
            'label' => 'システム',
            'description' => 'その他のシステムログ',
            'types' => ['system'],
            'fallback_tab' => 'システム',
            'default' => true,
        ],
        'newcomer' => [
            'label' => '新規冒険者',
            'description' => '新しい冒険者の到着ログ',
            'types' => ['newcomer'],
            'fallback_tab' => 'システム',
            'default' => false,
        ],
    ];

    // チャット入力用プロパティ
    public string $message = '';
    public string $chatTarget = 'all'; // 'all', 'private'
    public ?int $receiverId = null;
    public ?int $editingLogId = null;
    public string $editingMessage = '';

    public function mount()
    {
        $this->allTabVisibility = $this->storedAllTabVisibility();
    }

    public function setTab($tab)
    {
        if (!in_array($tab, ['all', 'system', 'chat', 'private', 'drop', 'info'], true)) {
            $tab = 'all';
        }

        $this->activeTab = $tab;
    }

    public function toggleExpanded()
    {
        $this->isExpanded = !$this->isExpanded;
    }

    public function loadMore()
    {
        $this->logLimit = min(self::LOG_MAX, $this->logLimit + self::LOG_STEP);
        $this->isExpanded = true;
    }

    public function setAllTabVisibility(string $key, bool $enabled): void
    {
        if (! array_key_exists($key, self::ALL_TAB_FILTERS)) {
            return;
        }

        $this->allTabVisibility = $this->normalizedAllTabVisibility(array_merge(
            $this->allTabVisibility,
            [$key => $enabled],
        ));

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if ($character && $this->canPersistAllTabVisibility()) {
            $character->forceFill([
                'chat_all_tab_visibility' => $this->allTabVisibility,
            ])->save();
        }
    }

    public function sendMessage(PublicLogService $logService)
    {
        $this->validate([
            'message' => 'required|string|max:100',
        ]);

        $character = auth()->user()->currentCharacter();
        if (!$character) {
            return;
        }

        if ($this->chatTarget === 'private' && $this->receiverId) {
            // 個人宛（手紙）
            $logService->addLog('private', $this->message, $character, 1, $this->receiverId);
            session()->flash('message', '手紙を送信しました。');
            // 自分の送信したメッセージが見えるようにタブを切り替える
            if ($this->activeTab !== 'private') {
                $this->activeTab = 'private';
            }
        } else {
            // 全体
            $logService->addLog('chat', $this->message, $character);
        }

        $this->message = ''; // 入力欄をクリア
    }

    public function startEdit(int $logId): void
    {
        $character = auth()->user()->currentCharacter();
        if (!$character) {
            return;
        }

        $log = PublicLog::query()
            ->whereKey($logId)
            ->where('character_id', $character->id)
            ->whereIn('type', ['chat', 'private'])
            ->first();

        if (!$log) {
            return;
        }

        $this->editingLogId = (int) $log->id;
        $this->editingMessage = (string) $log->message;
    }

    public function cancelEdit(): void
    {
        $this->editingLogId = null;
        $this->editingMessage = '';
    }

    public function updateMessage(): void
    {
        $this->validate([
            'editingMessage' => 'required|string|max:100',
        ]);

        $character = auth()->user()->currentCharacter();
        if (!$character || !$this->editingLogId) {
            return;
        }

        $log = PublicLog::query()
            ->whereKey($this->editingLogId)
            ->where('character_id', $character->id)
            ->whereIn('type', ['chat', 'private'])
            ->first();

        if (!$log) {
            $this->cancelEdit();
            return;
        }

        $log->forceFill([
            'message' => $this->editingMessage,
        ])->save();

        $this->cancelEdit();
    }

    #[On('set-chat-reply')]
    public function setReplyTarget($targetId)
    {
        // Alpineからオブジェクトで渡ってきた場合の対応
        if (is_array($targetId)) {
            if (isset($targetId['targetId'])) {
                $targetId = $targetId['targetId'];
            } elseif (isset($targetId[0])) {
                $targetId = $targetId[0];
            }
        }

        $this->chatTarget = 'private';
        $this->receiverId = $targetId;
    }

    public function render(PublicLogService $logService)
    {
        // フィルタリングのために少し多めに取得（自分のIDを渡す）
        $characterId = auth()->check() && auth()->user()->currentCharacter() ? auth()->user()->currentCharacter()->id : null;
        $displayLimit = $this->logLimit;
        $fetchLimit = $displayLimit <= 15 ? 50 : min(2000, $displayLimit * 4);
        $publicLogs = $logService->getRecentLogs($fetchLimit, $characterId);
        $systemLogs = [];
        $count = 0;

        foreach ($publicLogs as $log) {
            $isNewcomerLog = $this->isNewcomerLog($log);

            if ($this->activeTab === 'all' && ! $this->shouldShowInAllTab($log, $isNewcomerLog)) {
                continue;
            }

            // タブによるフィルタリング
            if ($this->activeTab !== 'all') {
                if ($this->activeTab === 'system' && !in_array($log->type, ['system', 'area', 'job', 'growth', 'job_change', 'newcomer', 'sub_area', 'arena', 'duel', 'valmon'], true) && ! $isNewcomerLog) {
                    continue;
                }
                if ($this->activeTab === 'drop' && $log->type !== 'drop') {
                    continue;
                }
                if ($this->activeTab === 'chat' && !in_array($log->type, ['chat', 'guild'], true)) {
                    continue;
                }
                if ($this->activeTab === 'info' && !in_array($log->type, ['info', 'admin', 'notice'], true)) {
                    continue;
                }
                if ($this->activeTab === 'private' && $log->type !== 'private') {
                    continue;
                }
            }

            // 表示形式の整形
            $displayMessage = $log->message;
            $replyPrefix = '';
            $replyId = null;
            $isSender = false;

            if ($log->type === 'chat') {
                $replyPrefix = '【' . ($log->character ? $log->character->name : '名無し') . '】';
                $replyId = $log->character_id;
            } elseif ($log->type === 'private') {
                $senderName = $log->character ? $log->character->name : '不明';
                $receiverName = $log->receiver ? $log->receiver->name : '不明';
                
                if ($log->character_id == $characterId) {
                    // 自分が送信側
                    $replyPrefix = '【To ' . $receiverName . '】';
                    $replyId = $log->receiver_id;
                    $isSender = true;
                } else {
                    // 自分が受信側
                    $replyPrefix = '【From ' . $senderName . '】';
                    $replyId = $log->character_id;
                }
            } elseif ($log->type === 'admin') {
                $replyPrefix = '【管理人】';
            } elseif ($log->type === 'notice') {
                $replyPrefix = '【お知らせ】';
            } elseif ($log->type === 'guild') {
                $replyPrefix = '【' . ($log->character ? $log->character->name : '名無し') . '】';
                $replyId = $log->character_id;
            }

            $systemLogs[] = [
                'id' => $log->id,
                'type' => $isNewcomerLog ? 'newcomer' : $log->type,
                'message' => $displayMessage,
                'reply_prefix' => $replyPrefix,
                'reply_id' => $replyId,
                'is_sender' => $isSender,
                'can_edit' => $characterId
                    && (int) $log->character_id === (int) $characterId
                    && in_array($log->type, ['chat', 'private'], true),
                'is_edited' => $log->updated_at && $log->created_at && $log->updated_at->gt($log->created_at->copy()->addSecond()),
                'time' => $log->created_at ? $log->created_at->format('H:i') : date('H:i'),
            ];

            $count++;
            if ($count >= $displayLimit) {
                break;
            }
        }

        $availableReceivers = [];
        if ($characterId && $this->shouldLoadReceivers()) {
            $availableReceivers = Character::visibleToPublic()
                ->where('id', '!=', $characterId)
                ->orderBy('updated_at', 'desc')
                ->limit(100) // 直近アクティブな100人など
                ->get(['id', 'name']);

            if ($this->receiverId
                && ! $availableReceivers->contains('id', (int) $this->receiverId)) {
                $selectedReceiver = Character::visibleToPublic()
                    ->whereKey($this->receiverId)
                    ->where('id', '!=', $characterId)
                    ->first(['id', 'name']);

                if ($selectedReceiver) {
                    $availableReceivers->prepend($selectedReceiver);
                }
            }
            
            // デフォルトの受信者をセット
            if (!$this->receiverId && $availableReceivers->isNotEmpty()) {
                $this->receiverId = $availableReceivers->first()->id;
            }
        }

        return view('livewire.chat-log', [
            'systemLogs' => $systemLogs,
            'availableReceivers' => $availableReceivers,
            'allTabFilterOptions' => $this->allTabFilterOptions(),
        ]);
    }

    private function shouldLoadReceivers(): bool
    {
        return $this->chatTarget === 'private'
            || $this->activeTab === 'private';
    }

    private function isNewcomerLog(PublicLog $log): bool
    {
        return $log->type === 'newcomer'
            || (str_starts_with((string) $log->message, '新しい冒険者')
                && str_contains((string) $log->message, 'ヴァルゼリアの地に降り立ちました。'));
    }

    private function shouldShowInAllTab(PublicLog $log, bool $isNewcomerLog): bool
    {
        if (in_array($log->type, ['private', 'admin_private', 'admin_private_reply'], true)) {
            return false;
        }

        $key = $isNewcomerLog ? 'newcomer' : $this->filterKeyForType((string) $log->type);
        if ($key === null) {
            return true;
        }

        return (bool) ($this->allTabVisibility[$key] ?? self::ALL_TAB_FILTERS[$key]['default']);
    }

    private function filterKeyForType(string $type): ?string
    {
        foreach (self::ALL_TAB_FILTERS as $key => $option) {
            if (in_array($type, $option['types'], true)) {
                return $key;
            }
        }

        return null;
    }

    private function defaultAllTabVisibility(): array
    {
        return collect(self::ALL_TAB_FILTERS)
            ->mapWithKeys(fn (array $option, string $key): array => [$key => (bool) $option['default']])
            ->all();
    }

    private function storedAllTabVisibility(): array
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if (! $character || ! $this->canPersistAllTabVisibility()) {
            return $this->defaultAllTabVisibility();
        }

        return $this->normalizedAllTabVisibility((array) ($character->chat_all_tab_visibility ?? []));
    }

    private function normalizedAllTabVisibility(array $visibility): array
    {
        $defaults = $this->defaultAllTabVisibility();

        return collect($defaults)
            ->mapWithKeys(fn (bool $default, string $key): array => [
                $key => array_key_exists($key, $visibility) ? (bool) $visibility[$key] : $default,
            ])
            ->all();
    }

    private function canPersistAllTabVisibility(): bool
    {
        return Schema::hasColumn('characters', 'chat_all_tab_visibility');
    }

    private function allTabFilterOptions(): array
    {
        return collect(self::ALL_TAB_FILTERS)
            ->map(function (array $option, string $key): array {
                return [
                    'key' => $key,
                    'label' => $option['label'],
                    'description' => $option['description'],
                    'fallback_tab' => $option['fallback_tab'],
                    'enabled' => (bool) ($this->allTabVisibility[$key] ?? $option['default']),
                ];
            })
            ->values()
            ->all();
    }
}
