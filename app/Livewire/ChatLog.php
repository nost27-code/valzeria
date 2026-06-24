<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Services\PublicLogService;

class ChatLog extends Component
{
    public string $activeTab = 'all';
    public bool $isExpanded = false;
    
    // チャット入力用プロパティ
    public string $message = '';
    public string $chatTarget = 'all'; // 'all', 'guild', 'private'
    public ?int $receiverId = null;

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
        } elseif ($this->chatTarget === 'guild') {
            // ギルド（未実装のためとりあえず全体と同じだが type は guild にする等。今はダミー）
            $logService->addLog('guild', $this->message, $character);
            session()->flash('message', 'ギルドチャットに送信しました。');
        } else {
            // 全体
            $logService->addLog('chat', $this->message, $character);
        }

        $this->message = ''; // 入力欄をクリア
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
        $displayLimit = $this->isExpanded ? 25 : 15;
        $publicLogs = $logService->getRecentLogs($this->isExpanded ? 90 : 50, $characterId);
        $systemLogs = [];
        $count = 0;

        foreach ($publicLogs as $log) {
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
                if ($this->activeTab === 'info' && $log->type !== 'info') {
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
            } elseif ($log->type === 'guild') {
                $replyPrefix = '【' . ($log->character ? $log->character->name : '名無し') . '】';
                $replyId = $log->character_id;
            }

            $systemLogs[] = [
                'type' => $log->type,
                'message' => $displayMessage,
                'reply_prefix' => $replyPrefix,
                'reply_id' => $replyId,
                'is_sender' => $isSender,
                'time' => $log->created_at ? $log->created_at->format('H:i') : date('H:i'),
            ];

            $count++;
            if ($count >= $displayLimit) {
                break;
            }
        }

        // 宛先リストの取得（個人チャット用：自分以外の全キャラクターを簡易的に取得）
        // プレイヤー数が増えると重くなるため、将来的にはログイン中のみにする等の工夫が必要
        $availableReceivers = [];
        if ($characterId) {
            $availableReceivers = \App\Models\Character::where('id', '!=', $characterId)
                ->orderBy('updated_at', 'desc')
                ->limit(100) // 直近アクティブな100人など
                ->get(['id', 'name']);
            
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
}
