<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use App\Models\PublicLog;
use App\Models\Character;
use App\Services\CharacterNotificationService;
use App\Services\PublicLogService;
use App\Support\PrivateChatThemeCatalog;
use Illuminate\Support\Facades\Auth;

#[Layout('components.layouts.facility')]
class MessageBox extends Component
{
    use WithPagination;

    private const ADMIN_CONVERSATION_ID = -1;

    public $activeTab = 'threads'; // threads, create
    
    // 新規作成用
    public $message = '';
    public $receiverId = '';
    public $replyingToName = '';
    public bool $showSendConfirm = false;
    public string $confirmReceiverName = '';
    public ?int $selectedConversationId = null;
    public string $receiverSearch = '';
    public string $privateChatThemeKey = PrivateChatThemeCatalog::DEFAULT_KEY;

    public function mount()
    {
        $character = Auth::user()?->currentCharacter();
        $this->privateChatThemeKey = $this->selectedPrivateChatThemeKey($character);

        // 旧URLのタブ指定も会話一覧へ寄せる
        if (request()->has('tab')) {
            $this->activeTab = request('tab') === 'create' ? 'create' : 'threads';
        }

        if ($this->activeTab === 'threads') {
            $this->markMessageNotificationsRead();
        }
    }

    public function setTab($tabName)
    {
        $this->activeTab = $tabName === 'create' ? 'create' : 'threads';
        if ($tabName !== 'create') {
            $this->replyingToName = '';
            $this->resetSendConfirmation();
        }
        if ($this->activeTab !== 'threads') {
            $this->selectedConversationId = null;
        }
        if ($this->activeTab === 'threads') {
            $this->markMessageNotificationsRead();
        }
        $this->resetPage(); // ページネーションをリセット
    }

    public function openConversation(int $characterId): void
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || $characterId === (int) $character->id) return;

        $partner = Character::find($characterId);
        if (!$partner) return;

        $this->activeTab = 'threads';
        $this->selectedConversationId = $characterId;
        $this->receiverId = (string) $characterId;
        $this->replyingToName = $partner->name;
        $this->message = '';
        $this->resetSendConfirmation();
        $this->markMessageNotificationsRead();
        $this->resetPage();
    }

    public function openAdminConversation(): void
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return;
        }

        $this->activeTab = 'threads';
        $this->selectedConversationId = self::ADMIN_CONVERSATION_ID;
        $this->receiverId = '';
        $this->replyingToName = '管理人';
        $this->message = '';
        $this->resetSendConfirmation();
        $this->markMessageNotificationsRead();
        $this->resetPage();
    }

    public function backToConversationList(): void
    {
        $this->selectedConversationId = null;
        $this->receiverId = '';
        $this->replyingToName = '';
        $this->message = '';
        $this->resetSendConfirmation();
        $this->resetPage();
    }

    public function replyTo($receiverId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) return;

        $receiver = Character::where('id', $receiverId)
            ->where('id', '!=', $character->id)
            ->first();

        if (!$receiver) return;

        $this->receiverId = (string) $receiver->id;
        $this->replyingToName = $receiver->name;
        $this->message = '';
        $this->resetSendConfirmation();
        $this->activeTab = 'threads';
        $this->selectedConversationId = (int) $receiver->id;
        $this->resetPage();
    }

    public function confirmMessage()
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) return;

        if ($character->is_frozen) {
            $this->addError('message', 'アカウントが凍結されているため送信できません。');
            return;
        }

        if ($this->isAdminConversation()) {
            $this->validate([
                'message' => 'required|string|max:200',
            ]);
            $this->confirmReceiverName = '管理人';
            $this->showSendConfirm = true;
            return;
        }

        $this->validateMessageInput();

        $receiver = Character::where('id', $this->receiverId)
            ->where('id', '!=', $character->id)
            ->first();

        if (!$receiver) {
            $this->addError('receiverId', '宛先を選択してください。');
            return;
        }

        $this->confirmReceiverName = $receiver->name;
        $this->showSendConfirm = true;
    }

    public function cancelSendConfirm(): void
    {
        $this->resetSendConfirmation();
    }

    public function updatedReceiverSearch(): void
    {
        $this->resetPage();
    }

    public function setPrivateChatTheme(string $themeKey): void
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || !array_key_exists($themeKey, PrivateChatThemeCatalog::themes())) {
            return;
        }

        $character->private_chat_theme = $themeKey;
        $character->save();

        $this->privateChatThemeKey = $themeKey;
    }

    public function sendMessage(PublicLogService $logService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) return;

        if ($character->is_frozen) {
            $this->addError('message', 'アカウントが凍結されているため送信できません。');
            return;
        }

        if ($this->isAdminConversation()) {
            $this->validate([
                'message' => 'required|string|max:200',
            ]);
            $logService->addAdminPrivateReply($this->message, $character);

            $this->message = '';
            $this->resetSendConfirmation();
            return;
        }

        $this->validateMessageInput();

        // PublicLogService を利用して送信
        $logService->addLog('private', $this->message, $character, 1, (int) $this->receiverId);

        // 送信後は会話画面に残す
        $sentReceiverId = (int) $this->receiverId;
        $this->message = '';
        $this->resetSendConfirmation();

        $this->activeTab = 'threads';
        $this->selectedConversationId = $sentReceiverId;
        $this->receiverId = (string) $sentReceiverId;
    }

    public function render()
    {
        $character = Auth::user()->currentCharacter();
        
        $messages = [];
        $availableReceivers = [];
        $conversations = collect();
        $latestConversationByPartner = collect();
        $threadMessages = collect();
        $selectedConversation = null;

        if ($character) {
            $this->privateChatThemeKey = $this->selectedPrivateChatThemeKey($character);

            $privateLogs = PublicLog::with(['character', 'receiver'])
                ->where(function ($query) use ($character) {
                    $query->where(function ($privateQuery) use ($character) {
                        $privateQuery->where('type', 'private')
                            ->where(function ($participantQuery) use ($character) {
                                $participantQuery->where('receiver_id', $character->id)
                                    ->orWhere('character_id', $character->id);
                            });
                    })->orWhere(function ($adminQuery) use ($character) {
                        $adminQuery->whereIn('type', ['admin_private', 'admin_private_reply'])
                            ->where('receiver_id', $character->id);
                    });
                })
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->limit(300)
                ->get();

            $latestConversationByPartner = $this->conversationCards($privateLogs, $character)
                ->keyBy('partner_id');

            if ($this->activeTab === 'threads') {
                $conversations = $latestConversationByPartner->values();

                if ($this->selectedConversationId === self::ADMIN_CONVERSATION_ID) {
                    $selectedConversation = ['name' => '管理人', 'is_admin' => true];
                    $threadMessages = PublicLog::query()
                        ->whereIn('type', ['admin_private', 'admin_private_reply'])
                        ->where('receiver_id', $character->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->limit(120)
                        ->get();
                } elseif ($this->selectedConversationId) {
                    $selectedConversation = Character::find($this->selectedConversationId);

                    if ($selectedConversation) {
                        $threadMessages = PublicLog::with(['character', 'receiver'])
                            ->where('type', 'private')
                            ->where(function ($query) use ($character) {
                                $query->where(function ($q) use ($character) {
                                    $q->where('character_id', $character->id)
                                        ->where('receiver_id', $this->selectedConversationId);
                                })->orWhere(function ($q) use ($character) {
                                    $q->where('character_id', $this->selectedConversationId)
                                        ->where('receiver_id', $character->id);
                                });
                            })
                            ->orderBy('created_at')
                            ->orderBy('id')
                            ->limit(120)
                            ->get();

                        $this->receiverId = (string) $selectedConversation->id;
                        $this->replyingToName = $selectedConversation->name;
                    } else {
                        $this->selectedConversationId = null;
                        $this->receiverId = '';
                        $this->replyingToName = '';
                    }
                }
            } elseif ($this->activeTab === 'create') {
                $search = trim($this->receiverSearch);

                $availableReceivers = Character::visibleToPublic()
                    ->where('id', '!=', $character->id)
                    ->when($search !== '', function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%');
                    })
                    ->orderByRaw('COALESCE(last_seen_at, updated_at) DESC')
                    ->limit(100)
                    ->get();

                if ($this->receiverId !== '' && !$availableReceivers->contains('id', (int) $this->receiverId)) {
                    $selectedReceiver = Character::visibleToPublic()
                        ->where('id', $this->receiverId)
                        ->where('id', '!=', $character->id)
                        ->first();

                    if ($selectedReceiver) {
                        $availableReceivers = $availableReceivers->prepend($selectedReceiver)->values();
                    }
                }

                $latestConversationByPartner = $this->latestConversationCardsForPartners(
                    $character,
                    $availableReceivers->pluck('id')->map(fn ($id) => (int) $id)->all()
                );
            }
        }

        return view('livewire.message-box', [
            'messages' => $messages,
            'availableReceivers' => $availableReceivers,
            'character' => $character,
            'conversations' => $conversations,
            'latestConversationByPartner' => $latestConversationByPartner,
            'threadMessages' => $threadMessages,
            'selectedConversation' => $selectedConversation,
            'privateChatTheme' => PrivateChatThemeCatalog::theme($this->privateChatThemeKey),
            'privateChatThemes' => PrivateChatThemeCatalog::themes(),
            'selectedPrivateChatThemeKey' => $this->privateChatThemeKey,
        ])->layout('components.layouts.facility', [
            'title' => '個人チャット',
            'headerIconImage' => 'images/icon/icon_015.webp',
            'bgImage' => 'images/bg-night.webp'
        ]);
    }

    private function markMessageNotificationsRead(): void
    {
        $character = Auth::user()?->currentCharacter();
        if (!$character) return;

        app(CharacterNotificationService::class)->markCategoryAsRead($character, 'message');
    }

    private function conversationCards($privateLogs, Character $character)
    {
        return $privateLogs
            ->map(function (PublicLog $log) use ($character) {
                if (in_array($log->type, ['admin_private', 'admin_private_reply'], true)) {
                    return [
                        'partner_id' => self::ADMIN_CONVERSATION_ID,
                        'partner' => null,
                        'name' => '管理人',
                        'last_message' => $log->message,
                        'last_at' => $log->created_at,
                        'is_mine' => $log->type === 'admin_private_reply',
                        'is_admin' => true,
                    ];
                }

                $isMine = (int) $log->character_id === (int) $character->id;
                $partner = $isMine ? $log->receiver : $log->character;

                if (!$partner) {
                    return null;
                }

                return [
                    'partner_id' => (int) $partner->id,
                    'partner' => $partner,
                    'last_message' => $log->message,
                    'last_at' => $log->created_at,
                    'is_mine' => $isMine,
                    'is_admin' => false,
                ];
            })
            ->filter()
            ->unique('partner_id')
            ->values();
    }

    private function latestConversationCardsForPartners(Character $character, array $partnerIds)
    {
        if (empty($partnerIds)) {
            return collect();
        }

        $privateLogs = PublicLog::with(['character', 'receiver'])
            ->where('type', 'private')
            ->where(function ($query) use ($character, $partnerIds) {
                $query->where(function ($q) use ($character, $partnerIds) {
                    $q->where('character_id', $character->id)
                        ->whereIn('receiver_id', $partnerIds);
                })->orWhere(function ($q) use ($character, $partnerIds) {
                    $q->whereIn('character_id', $partnerIds)
                        ->where('receiver_id', $character->id);
                });
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(1000)
            ->get();

        return $this->conversationCards($privateLogs, $character)->keyBy('partner_id');
    }

    private function validateMessageInput(): void
    {
        $this->validate([
            'message' => 'required|string|max:200',
            'receiverId' => 'required|exists:characters,id',
        ]);
    }

    private function isAdminConversation(): bool
    {
        return $this->selectedConversationId === self::ADMIN_CONVERSATION_ID;
    }

    private function resetSendConfirmation(): void
    {
        $this->showSendConfirm = false;
        $this->confirmReceiverName = '';
    }

    private function selectedPrivateChatThemeKey(?Character $character): string
    {
        $themeKey = (string) ($character?->private_chat_theme ?: PrivateChatThemeCatalog::DEFAULT_KEY);

        return array_key_exists($themeKey, PrivateChatThemeCatalog::themes())
            ? $themeKey
            : PrivateChatThemeCatalog::DEFAULT_KEY;
    }
}
