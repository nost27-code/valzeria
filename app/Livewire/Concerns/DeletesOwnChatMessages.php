<?php

namespace App\Livewire\Concerns;

use App\Services\ChatMessageDeletionService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

trait DeletesOwnChatMessages
{
    #[Locked]
    public ?int $pendingDeletionLogId = null;

    #[Locked]
    public string $pendingDeletionMessage = '';

    #[Locked]
    public bool $pendingDeletionIsPrivate = false;

    public string $chatDeletionStatus = '';

    public function confirmDeleteMessage(int $logId, ChatMessageDeletionService $service): void
    {
        $this->cancelDeleteMessage();
        $this->chatDeletionStatus = '';
        $character = auth()->user()?->currentCharacter();
        $log = $character ? $service->findOwnMessage($character, $logId) : null;
        if (! $log) {
            $this->addError('chatDeletion', '削除できるのは自分が送った全体・個人チャットだけです。削除済みの場合は表示を更新してください。');

            return;
        }

        $this->pendingDeletionLogId = (int) $log->id;
        $this->pendingDeletionMessage = (string) $log->message;
        $this->pendingDeletionIsPrivate = $log->type === 'private';
    }

    public function cancelDeleteMessage(): void
    {
        $this->pendingDeletionLogId = null;
        $this->pendingDeletionMessage = '';
        $this->pendingDeletionIsPrivate = false;
        $this->resetErrorBag('chatDeletion');
    }

    public function deleteMessage(ChatMessageDeletionService $service): void
    {
        $character = auth()->user()?->currentCharacter();
        $logId = $this->pendingDeletionLogId;
        if (! $character || ! $logId) {
            return;
        }

        $deleted = $service->deleteOwnMessage($character, $logId);
        $this->cancelDeleteMessage();
        if (! $deleted) {
            $this->addError('chatDeletion', 'メッセージは削除済みか、削除できない状態です。');

            return;
        }

        $this->chatDeletionStatus = 'メッセージを削除しました。';
        $this->dispatch('chat-message-deleted', logId: $logId);
    }

    #[On('chat-message-deleted')]
    public function refreshAfterMessageDeletion(int $logId): void
    {
        if ($this->pendingDeletionLogId === $logId) {
            $this->cancelDeleteMessage();
        }
    }
}
