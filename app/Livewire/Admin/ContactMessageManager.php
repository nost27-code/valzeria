<?php

namespace App\Livewire\Admin;

use App\Models\AdminMailMessage;
use App\Models\Character;
use App\Models\ContactMessage;
use App\Services\ContactMailboxImportService;
use App\Services\ContactMailReplyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ContactMessageManager extends Component
{
    use WithPagination;

    public string $status = 'new';

    public string $search = '';

    public ?int $selectedMessageId = null;

    public ?string $importMessage = null;

    public ?string $replyMessage = null;

    public string $replyBody = '';

    public string $composeSearch = '';

    public ?int $composeCharacterId = null;

    public string $composeSubject = '';

    public string $composeBody = '';

    public ?string $composeMessage = null;

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selectedMessageId = null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectMessage(int $messageId): void
    {
        $message = ContactMessage::query()->findOrFail($messageId);

        if ($message->status === 'new') {
            $message->update([
                'status' => 'read',
                'read_at' => now(),
            ]);
        }

        $this->selectedMessageId = $message->id;
        $this->replyMessage = null;
        $this->replyBody = $this->defaultReplyBody($message);
    }

    public function updatedComposeSearch(): void
    {
        $this->composeCharacterId = null;
        $this->composeMessage = null;
    }

    public function selectComposeRecipient(int $characterId): void
    {
        $character = Character::query()->with('user')->findOrFail($characterId);

        if (!$character->user?->email) {
            $this->composeMessage = 'メールアドレスがないプレイヤーは選択できません。';
            return;
        }

        $this->composeCharacterId = $character->id;
        $this->composeSearch = $character->name . ' / ' . $character->user->email;
        $this->composeMessage = null;
    }

    public function markStatus(int $messageId, string $status): void
    {
        if (!in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
            return;
        }

        $values = ['status' => $status];

        if ($status === 'new') {
            $values['read_at'] = null;
            $values['replied_at'] = null;
        } elseif ($status === 'read') {
            $values['read_at'] = now();
            $values['replied_at'] = null;
        } elseif ($status === 'replied') {
            $values['read_at'] = now();
            $values['replied_at'] = now();
        }

        ContactMessage::query()->whereKey($messageId)->update($values);
    }

    public function importMailbox(ContactMailboxImportService $importService): void
    {
        try {
            $result = $importService->import();
            $this->importMessage = "メール取り込み完了: 確認 {$result['checked']} 件 / 新規 {$result['imported']} 件 / 既存 {$result['skipped']} 件";
            $this->status = 'new';
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->importMessage = 'メール取り込みに失敗しました: ' . $e->getMessage();
        }
    }

    public function sendReply(ContactMailReplyService $replyService): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $message = ContactMessage::query()->findOrFail($this->selectedMessageId);

        try {
            $replyService->send($message, $this->replyBody, Auth::user());
            $this->replyMessage = '返信メールを送信しました。';
        } catch (\Throwable $e) {
            $this->replyMessage = '返信メールの送信に失敗しました: ' . $e->getMessage();
        }
    }

    public function sendNewMail(ContactMailReplyService $mailService): void
    {
        $this->validate([
            'composeCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'composeSubject' => ['required', 'string', 'min:1', 'max:160'],
            'composeBody' => ['required', 'string', 'min:1', 'max:5000'],
        ], [
            'composeCharacterId.required' => '送信先プレイヤーを選択してください。',
            'composeSubject.required' => '件名を入力してください。',
            'composeBody.required' => '本文を入力してください。',
        ]);

        $character = Character::query()->with('user')->findOrFail($this->composeCharacterId);

        try {
            $mailService->sendNew($character, $this->composeSubject, $this->composeBody, Auth::user());
            $this->composeMessage = "{$character->name} へメールを送信しました。";
            $this->composeSubject = '';
            $this->composeBody = '';
        } catch (\Throwable $e) {
            $this->composeMessage = '新規メールの送信に失敗しました: ' . $e->getMessage();
        }
    }

    public function render()
    {
        $query = ContactMessage::query()
            ->with(['user', 'character'])
            ->latest();

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(function ($inner) use ($search) {
                $inner->where('sender_email', 'like', $search)
                    ->orWhere('sender_name', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('body', 'like', $search);
            });
        }

        $messages = $query->paginate(20);
        $selectedMessage = $this->selectedMessageId
            ? ContactMessage::with(['user', 'character', 'replies.adminUser'])->find($this->selectedMessageId)
            : $messages->first();

        if ($selectedMessage && $this->selectedMessageId === null) {
            $this->selectedMessageId = $selectedMessage->id;
            $this->replyBody = $this->defaultReplyBody($selectedMessage);
        }

        return view('livewire.admin.contact-message-manager', [
            'messages' => $messages,
            'selectedMessage' => $selectedMessage,
            'counts' => [
                'new' => ContactMessage::where('status', 'new')->count(),
                'read' => ContactMessage::where('status', 'read')->count(),
                'replied' => ContactMessage::where('status', 'replied')->count(),
                'archived' => ContactMessage::where('status', 'archived')->count(),
                'all' => ContactMessage::count(),
            ],
            'composeCandidates' => $this->composeCandidates(),
            'selectedComposeCharacter' => $this->composeCharacterId
                ? Character::query()->with('user')->find($this->composeCharacterId)
                : null,
            'recentAdminMails' => AdminMailMessage::query()
                ->with(['character', 'user', 'adminUser'])
                ->latest()
                ->limit(5)
                ->get(),
        ])->layout('components.layouts.admin');
    }

    private function composeCandidates()
    {
        $keyword = trim($this->composeSearch);

        if ($keyword === '' || $this->composeCharacterId !== null) {
            return collect();
        }

        return Character::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->whereNotNull('email'))
            ->where(function ($query) use ($keyword) {
                $query->where('name', 'like', "%{$keyword}%")
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery->where('name', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%");
                    });

                if (ctype_digit($keyword)) {
                    $query->orWhere('id', (int) $keyword)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('id', (int) $keyword));
                }
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
    }

    private function defaultReplyBody(ContactMessage $message): string
    {
        $name = $message->sender_name ?: 'お問い合わせいただいた方';

        return "{$name} 様\n\nお問い合わせありがとうございます。\nヴァルゼリアの冒険者 運営です。\n\n\n\n今後ともヴァルゼリアの冒険者をよろしくお願いいたします。";
    }
}
