<?php

namespace App\Livewire\Admin;

use App\Models\ChatMessageDeletionLog;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class DeletedChatLogManager extends Component
{
    use WithPagination;

    public string $searchQuery = '';
    public string $typeFilter = 'all';
    public int $perPage = 50;

    public function updatedSearchQuery(): void
    {
        $this->searchQuery = trim($this->searchQuery);
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        if (! in_array($this->typeFilter, ['all', 'chat', 'private'], true)) {
            $this->typeFilter = 'all';
        }
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [50, 100, 200], true) ? $this->perPage : 50;
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(auth()->user()?->fresh()?->role === 'admin', 403);
        $ready = Schema::hasTable('chat_message_deletion_logs');
        $logs = collect();
        if ($ready) {
            $query = ChatMessageDeletionLog::query();
            if (in_array($this->typeFilter, ['chat', 'private'], true)) {
                $query->where('type', $this->typeFilter);
            }
            if ($this->searchQuery !== '') {
                $search = '%'.$this->searchQuery.'%';
                $query->where(function ($q) use ($search): void {
                    $q->where('sender_name', 'like', $search)
                        ->orWhere('receiver_name', 'like', $search)
                        ->orWhere('message', 'like', $search);
                    if (ctype_digit($this->searchQuery)) {
                        $id = (int) $this->searchQuery;
                        $q->orWhere('character_id', $id)->orWhere('receiver_id', $id)
                            ->orWhere('user_id', $id)->orWhere('receiver_user_id', $id);
                    }
                });
            }
            $logs = $query->orderByDesc('deleted_at')->orderByDesc('id')
                ->paginate(in_array($this->perPage, [50, 100, 200], true) ? $this->perPage : 50);
        }

        return view('livewire.admin.deleted-chat-log-manager', compact('logs', 'ready'))
            ->layout('components.layouts.admin');
    }
}
