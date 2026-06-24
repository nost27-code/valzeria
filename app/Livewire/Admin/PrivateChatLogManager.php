<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class PrivateChatLogManager extends Component
{
    use WithPagination;

    public string $searchQuery = '';
    public int $perPage = 100;

    public function updatedSearchQuery(): void
    {
        $this->searchQuery = trim($this->searchQuery);
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [50, 100, 200], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    public function render()
    {
        $logs = collect();
        $totalCount = 0;
        $canReadPrivateLogs = Schema::hasTable('public_logs')
            && Schema::hasColumn('public_logs', 'receiver_id');

        if ($canReadPrivateLogs) {
            $query = DB::table('public_logs')
                ->leftJoin('characters as sender', 'public_logs.character_id', '=', 'sender.id')
                ->leftJoin('characters as receiver', 'public_logs.receiver_id', '=', 'receiver.id')
                ->where('public_logs.type', 'private')
                ->select([
                    'public_logs.id',
                    'public_logs.created_at',
                    'public_logs.message',
                    'sender.name as sender_name',
                    'receiver.name as receiver_name',
                ]);

            if ($this->searchQuery !== '') {
                $search = '%' . $this->searchQuery . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('sender.name', 'like', $search)
                        ->orWhere('receiver.name', 'like', $search)
                        ->orWhere('public_logs.message', 'like', $search);
                });
            }

            $totalCount = (clone $query)->count();

            $logs = $query
                ->orderByDesc('public_logs.created_at')
                ->orderByDesc('public_logs.id')
                ->paginate($this->perPage);
        }

        return view('livewire.admin.private-chat-log-manager', [
            'logs' => $logs,
            'totalCount' => $totalCount,
            'canReadPrivateLogs' => $canReadPrivateLogs,
        ])->layout('components.layouts.admin');
    }
}
