<?php

namespace App\Livewire\Admin;

use App\Models\BugReport;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Livewire\Component;
use Livewire\WithPagination;

class BugReportManager extends Component
{
    use WithPagination;

    public string $status = 'new';

    public string $search = '';

    public ?int $selectedReportId = null;

    public string $replyMessage = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selectedReportId = null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectReport(int $reportId): void
    {
        $report = BugReport::query()->findOrFail($reportId);

        if ($report->status === 'new') {
            $report->update(['status' => 'read', 'read_at' => now()]);
        }

        $this->selectedReportId = $report->id;
        $this->resetValidation('replyMessage');
        $this->replyMessage = '';
    }

    public function sendReply(PublicLogService $logService): void
    {
        $this->validate([
            'replyMessage' => ['required', 'string', 'max:200'],
        ]);

        $report = $this->selectedReportId
            ? BugReport::query()->with('character')->find($this->selectedReportId)
            : null;

        if (! $report || ! $report->character) {
            $this->addError('replyMessage', '送信先の冒険者が見つかりません。');
            return;
        }

        $logService->addAdminPrivateMessage(trim($this->replyMessage), $report->character);

        if ($report->status === 'new') {
            $report->update(['status' => 'read', 'read_at' => now()]);
        }

        $this->replyMessage = '';
        session()->flash('status', $report->character->name . 'さんへ管理人メッセージを送信しました。');
    }

    public function markStatus(int $reportId, string $status): void
    {
        if (!in_array($status, ['new', 'read', 'resolved', 'archived'], true)) {
            return;
        }

        BugReport::query()->whereKey($reportId)->update([
            'status' => $status,
            'read_at' => $status === 'new' ? null : now(),
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);
    }

    public function render()
    {
        $query = BugReport::query()->with(['character', 'attachments'])->latest();

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('body', 'like', $search)
                    ->orWhereHas('character', fn ($character) => $character->where('name', 'like', $search));
            });
        }

        $reports = $query->paginate(20);
        $selectedReport = $this->selectedReportId
            ? BugReport::query()->with(['character.jobClass', 'attachments'])->find($this->selectedReportId)
            : $reports->first();

        if ($selectedReport && $this->selectedReportId === null) {
            $this->selectedReportId = $selectedReport->id;
        }

        $adminConversation = $selectedReport?->character_id
            ? PublicLog::query()
                ->with('character')
                ->whereIn('type', ['admin_private', 'admin_private_reply'])
                ->where('receiver_id', $selectedReport->character_id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(120)
                ->get()
            : collect();

        return view('livewire.admin.bug-report-manager', [
            'reports' => $reports,
            'selectedReport' => $selectedReport,
            'adminConversation' => $adminConversation,
            'counts' => [
                'new' => BugReport::where('status', 'new')->count(),
                'read' => BugReport::where('status', 'read')->count(),
                'resolved' => BugReport::where('status', 'resolved')->count(),
                'archived' => BugReport::where('status', 'archived')->count(),
                'all' => BugReport::count(),
            ],
        ])->layout('components.layouts.admin');
    }
}
