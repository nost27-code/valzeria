<?php

namespace App\Livewire;

use App\Models\Character;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatLog extends Component
{
    public string $activeTab = 'all';
    public bool $isExpanded = false;
    public int $logLimit = 15;

    const LOG_STEP = 50;
    const LOG_MAX  = 500;

    // チャット入力用プロパティ
    public string $message = '';
    public string $chatTarget = 'all'; // 'all', 'private'
    public ?int $receiverId = null;
    public ?int $editingLogId = null;
    public string $editingMessage = '';

    public function mount()
    {
        // マウント時の初期化などが必要な場合はここで行う
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
            if ($this->activeTab === 'all' && $log->type === 'private') {
                continue;
            }

            // タブによるフィルタリング
            if ($this->activeTab !== 'all') {
                if ($this->activeTab === 'system' && !in_array($log->type, ['system', 'area', 'job', 'growth'])) {
                    continue;
                }
                if ($this->activeTab === 'drop' && $log->type !== 'drop') {
                    continue;
                }
                if ($this->activeTab === 'chat' && $log->type !== 'chat') {
                    continue;
                }
                if ($this->activeTab === 'info' && !in_array($log->type, ['info', 'admin'], true)) {
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
            } elseif ($log->type === 'guild') {
                $replyPrefix = '【' . ($log->character ? $log->character->name : '名無し') . '】';
                $replyId = $log->character_id;
            }

            $systemLogs[] = [
                'id' => $log->id,
                'type' => $log->type,
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
            $availableReceivers = Character::where('id', '!=', $characterId)
                ->orderBy('updated_at', 'desc')
                ->limit(100) // 直近アクティブな100人など
                ->get(['id', 'name']);

            if ($this->receiverId
                && ! $availableReceivers->contains('id', (int) $this->receiverId)) {
                $selectedReceiver = Character::query()
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
        ]);
    }

    private function shouldLoadReceivers(): bool
    {
        return $this->chatTarget === 'private'
            || $this->activeTab === 'private';
    }
}
