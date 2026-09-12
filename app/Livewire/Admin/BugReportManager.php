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

    public string $kind = 'all';

    public string $search = '';

    public ?int $selectedReportId = null;

    public string $replyMessage = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selectedReportId = null;
    }

    public function updatedKind(): void
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

        $notificationContext = $report->isSuggestion()
            ? '改善要望への返答'
            : '不具合フォームへの返答';

        $logService->addAdminPrivateMessage(
            trim($this->replyMessage),
            $report->character,
            $notificationContext,
        );

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

    private function codexInvestigationText(BugReport $report): string
    {
        $reportedUrl = $report->reported_url
            ? explode('?', $report->reported_url, 2)[0]
            : '未取得';

        $title = $report->isSuggestion() ? '# 改善要望の検討依頼' : '# 不具合調査依頼';
        $intro = $report->isSuggestion()
            ? '「ヴァルゼリアの冒険者」の現行仕様と関連実装を確認し、以下の改善要望を検討してください。'
            : '「ヴァルゼリアの冒険者」の現行コードを確認し、以下の報告を調査してください。';
        $request = $report->isSuggestion()
            ? '要望の目的、現在の仕様、影響範囲、最小実装案、確認方法を日本語で整理してください。未確定の仕様や数値は推測せず、要裁定として示してください。'
            : '現在の仕様、原因、再現条件、影響範囲、問題なら最小修正案と確認方法を日本語で報告してください。';

        return implode("\n", [
            $title,
            '',
            $intro,
            $request,
            '',
            '## 報告情報',
            '- 種類: ' . $report->kindLabel(),
            '- 報告者: ' . ($report->character?->name ?? 'キャラクター不明'),
            '- 送信日時: ' . $report->created_at->format('Y/m/d H:i'),
            '- 職業: ' . ($report->character?->jobClass?->name ?? '未取得'),
            '- 報告元URL: ' . $reportedUrl,
            '- 利用環境: ' . ($report->user_agent ?: '未取得'),
            '- 添付画像: ' . $report->attachments->count() . '枚',
            '',
            '## 報告内容',
            $report->body,
            '',
            '## 添付画像について',
            '必要に応じて、この依頼文を貼り付けた後に添付画像を続けて貼り付けます。',
        ]);
    }

    public function render()
    {
        $query = BugReport::query()->with(['character', 'attachments'])->latest();

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if (in_array($this->kind, BugReport::KINDS, true)) {
            $query->where('kind', $this->kind);
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

        $statusCountQuery = BugReport::query();
        if (in_array($this->kind, BugReport::KINDS, true)) {
            $statusCountQuery->where('kind', $this->kind);
        }

        return view('livewire.admin.bug-report-manager', [
            'reports' => $reports,
            'selectedReport' => $selectedReport,
            'adminConversation' => $adminConversation,
            'codexInvestigationText' => $selectedReport ? $this->codexInvestigationText($selectedReport) : '',
            'counts' => [
                'new' => (clone $statusCountQuery)->where('status', 'new')->count(),
                'read' => (clone $statusCountQuery)->where('status', 'read')->count(),
                'resolved' => (clone $statusCountQuery)->where('status', 'resolved')->count(),
                'archived' => (clone $statusCountQuery)->where('status', 'archived')->count(),
                'all' => (clone $statusCountQuery)->count(),
            ],
            'kindCounts' => [
                'all' => BugReport::count(),
                BugReport::KIND_SUGGESTION => BugReport::where('kind', BugReport::KIND_SUGGESTION)->count(),
                BugReport::KIND_BUG => BugReport::where('kind', BugReport::KIND_BUG)->count(),
            ],
        ])->layout('components.layouts.admin');
    }
}
