<?php

namespace App\Livewire;

use App\Models\Character;
use App\Models\PublicLog;
use App\Services\Nation\NationChatService;
use App\Services\PublicLogService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatLog extends Component
{
    public string $activeTab = 'all';
    public bool $isExpanded = false;
    public int $logLimit = 50;
    public array $allTabVisibility = [];
    #[Locked]
    public ?int $currentCharacterId = null;
    #[Locked]
    public ?string $logsVersion = null;

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
    public string $nationChatRequestId = '';

    public function mount(): void
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        $this->currentCharacterId = $character?->id;
        $this->allTabVisibility = $this->storedAllTabVisibility($character);
        $this->rotateNationChatRequestId();
    }

    public function setTab($tab)
    {
        if (!in_array($tab, ['all', 'system', 'chat', 'nation', 'private', 'drop', 'info'], true)
            || ($tab === 'nation' && ! $this->nationChatEnabled())) {
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
        if ($this->activeTab === 'nation') {
            return;
        }

        $this->logLimit = min(self::LOG_MAX, $this->logLimit + self::LOG_STEP);
        $this->isExpanded = true;
    }

    public function pollForUpdates(PublicLogService $logService): void
    {
        // 国家チャットは専用tableのため、pollごとに再描画して最新50件を取得する。
        if ($this->activeTab === 'nation') {
            return;
        }

        // 個人タブは受信者候補も更新対象なので、従来どおり全体を再描画する。
        if ($this->shouldLoadReceivers()) {
            return;
        }

        $version = $this->currentLogsVersion($logService);
        if ($this->logsVersion === $version) {
            $this->skipRender();

            return;
        }

        $this->logsVersion = $version;
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

    public function sendMessage(PublicLogService $logService, ?NationChatService $nationChatService = null)
    {
        $validated = $this->validate([
            'message' => 'required|string|max:100',
        ], [
            'message.required' => 'メッセージを入力してください。',
            'message.max' => 'メッセージは100文字以内で入力してください。',
        ]);

        $character = auth()->user()->currentCharacter();
        if (!$character) {
            return;
        }

        if ($this->activeTab === 'nation') {
            if (! $this->nationChatEnabled()) {
                $this->activeTab = 'all';
                $this->addError('message', '国家チャットは現在利用できません。');

                return;
            }

            $this->validate([
                'nationChatRequestId' => ['required', 'uuid'],
            ], [
                'nationChatRequestId.required' => '送信情報を更新するため、画面を再読み込みしてください。',
                'nationChatRequestId.uuid' => '送信情報を更新するため、画面を再読み込みしてください。',
            ]);

            try {
                ($nationChatService ?? app(NationChatService::class))
                    ->send($character, $validated['message'], $this->nationChatRequestId);
            } catch (\DomainException $exception) {
                $this->addError('message', $exception->getMessage());

                return;
            }

            $this->message = '';
            $this->rotateNationChatRequestId();

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

    public function render(PublicLogService $logService, ?NationChatService $nationChatService = null)
    {
        // 初期表示の「すべて」は表示条件をSQLへ渡し、必要な件数だけ取得する。
        $characterId = auth()->check() ? $this->currentCharacterId : null;
        $character = $characterId ? Character::query()->find($characterId) : null;
        $nationChatEnabled = $this->nationChatEnabled();
        if ($this->activeTab === 'nation' && ! $nationChatEnabled) {
            $this->activeTab = 'all';
        }

        if ($this->activeTab === 'nation') {
            $nationChatService ??= app(NationChatService::class);
            $nationChatAvailable = $character && $nationChatService->canUse($character);
            $systemLogs = $nationChatAvailable
                ? $nationChatService->recentFor($character)
                    ->map(fn ($message): array => [
                        'id' => 'nation-'.$message->id,
                        'type' => 'nation',
                        'message' => (string) $message->message,
                        'reply_prefix' => '【'.($message->character?->name ?? '不明な冒険者').'】',
                        'reply_id' => null,
                        'is_sender' => (int) $message->character_id === (int) $characterId,
                        'can_edit' => false,
                        'is_edited' => false,
                        'time' => $message->created_at?->format('H:i') ?? date('H:i'),
                    ])
                    ->all()
                : [];

            return view('livewire.chat-log', [
                'systemLogs' => $systemLogs,
                'availableReceivers' => [],
                'allTabFilterOptions' => $this->allTabFilterOptions(),
                'nationChatEnabled' => $nationChatEnabled,
                'nationChatAvailable' => (bool) $nationChatAvailable,
            ]);
        }

        $displayLimit = $this->logLimit;
        $fetchLimit = $displayLimit <= 15 ? 50 : min(2000, $displayLimit * 4);
        if ($this->activeTab === 'all') {
            [$excludedTypes, $newcomersVisible] = $this->allTabQueryFilters();
            $publicLogs = $logService->getRecentLogs(
                $displayLimit,
                $characterId,
                null,
                $excludedTypes,
                $newcomersVisible,
            );
        } elseif ($this->activeTab === 'drop') {
            $publicLogs = $logService->getRecentLogs($displayLimit, $characterId, ['drop']);
        } else {
            $publicLogs = $logService->getRecentLogs($fetchLimit, $characterId);
        }
        $this->logsVersion = $logService->logsVersion($publicLogs);
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
            'nationChatEnabled' => $nationChatEnabled,
            'nationChatAvailable' => false,
        ]);
    }

    public function placeholder()
    {
        return view('livewire.home-loading-placeholder', [
            'label' => 'チャット',
            'minHeight' => '16rem',
        ]);
    }

    private function shouldLoadReceivers(): bool
    {
        if ($this->activeTab === 'nation') {
            return false;
        }

        return $this->chatTarget === 'private'
            || $this->activeTab === 'private';
    }

    private function currentLogsVersion(PublicLogService $logService): string
    {
        $characterId = auth()->check() ? $this->currentCharacterId : null;
        $displayLimit = $this->logLimit;

        if ($this->activeTab === 'all') {
            [$excludedTypes, $newcomersVisible] = $this->allTabQueryFilters();

            return $logService->getRecentLogsVersion(
                $displayLimit,
                $characterId,
                null,
                $excludedTypes,
                $newcomersVisible,
            );
        }

        if ($this->activeTab === 'drop') {
            return $logService->getRecentLogsVersion($displayLimit, $characterId, ['drop']);
        }

        $fetchLimit = $displayLimit <= 15 ? 50 : min(2000, $displayLimit * 4);

        return $logService->getRecentLogsVersion($fetchLimit, $characterId);
    }

    private function allTabQueryFilters(): array
    {
        $excludedTypes = collect(self::ALL_TAB_FILTERS)
            ->except('newcomer')
            ->filter(fn (array $option, string $key): bool => ! (bool) ($this->allTabVisibility[$key] ?? $option['default']))
            ->flatMap(fn (array $option): array => $option['types'])
            ->concat(['private', 'admin_private', 'admin_private_reply', 'admin_reply_resolved'])
            ->unique()
            ->values()
            ->all();
        $newcomersVisible = (bool) ($this->allTabVisibility['newcomer'] ?? self::ALL_TAB_FILTERS['newcomer']['default']);

        return [$excludedTypes, $newcomersVisible];
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

    private function storedAllTabVisibility(?Character $character): array
    {
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

    private function nationChatEnabled(): bool
    {
        return (bool) config('features.nation_community_enabled', false)
            && Schema::hasTable('nation_chat_messages');
    }

    private function rotateNationChatRequestId(): void
    {
        $this->nationChatRequestId = (string) Str::uuid();
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
