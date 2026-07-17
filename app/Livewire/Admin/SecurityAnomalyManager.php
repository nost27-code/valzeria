<?php

namespace App\Livewire\Admin;

use App\Models\SecurityAnomalyCase;
use App\Models\SecurityAnomalyCaseEvent;
use App\Services\Admin\SecurityAnomalyDetectionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityAnomalyManager extends Component
{
    use WithPagination;

    public function boot(): void
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403);
    }

    public string $statusFilter = 'active';

    public string $ruleFilter = '';

    public string $search = '';

    public ?int $selectedCaseId = null;

    public string $resolutionNote = '';

    public ?string $lastScanMessage = null;

    public function mount(): void
    {
        $caseId = (int) request()->query('case_id', 0);
        $this->selectedCaseId = $caseId > 0 ? $caseId : null;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRuleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function runScan(SecurityAnomalyDetectionService $detection): void
    {
        $result = $detection->scan();
        $this->lastScanMessage = $result['skipped']
            ? '別の異常検知処理が実行中です。完了後に画面を更新してください。'
            : "新規 {$result['created']}件・既存更新 {$result['updated']}件を検知しました。";
        $this->resetPage();
    }

    public function selectCase(int $caseId): void
    {
        $this->selectedCaseId = $caseId;
        $this->resolutionNote = (string) (SecurityAnomalyCase::query()->find($caseId)?->resolution_note ?? '');
        $this->resetValidation();
    }

    public function updateStatus(string $status): void
    {
        if (! in_array($status, SecurityAnomalyCase::STATUSES, true) || ! $this->selectedCaseId) {
            return;
        }

        if (in_array($status, ['cleared', 'actioned'], true) && mb_strlen(trim($this->resolutionNote)) < 2) {
            $this->addError('resolutionNote', '「問題なし」「措置済み」にする場合は判断理由を入力してください。');

            return;
        }

        DB::transaction(function () use ($status): void {
            $case = SecurityAnomalyCase::query()->lockForUpdate()->findOrFail($this->selectedCaseId);
            if ($case->status === $status) {
                return;
            }

            $oldStatus = (string) $case->status;
            $now = now();
            $case->status = $status;
            $case->reviewed_by = Auth::id();
            $case->reviewed_at = $now;
            $case->resolution_note = trim($this->resolutionNote) !== '' ? trim($this->resolutionNote) : null;
            $case->resolved_at = in_array($status, ['cleared', 'actioned'], true) ? $now : null;
            $case->save();

            SecurityAnomalyCaseEvent::query()->create([
                'security_anomaly_case_id' => $case->id,
                'admin_user_id' => Auth::id(),
                'from_status' => $oldStatus,
                'to_status' => $status,
                'note' => trim($this->resolutionNote) !== '' ? trim($this->resolutionNote) : null,
                'created_at' => $now,
            ]);
        });

        $this->resetValidation();
    }

    public function saveNote(): void
    {
        if (! $this->selectedCaseId) {
            return;
        }

        $case = SecurityAnomalyCase::query()->findOrFail($this->selectedCaseId);
        $note = trim($this->resolutionNote);
        $case->update([
            'resolution_note' => $note !== '' ? $note : null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        SecurityAnomalyCaseEvent::query()->create([
            'security_anomaly_case_id' => $case->id,
            'admin_user_id' => Auth::id(),
            'from_status' => $case->status,
            'to_status' => $case->status,
            'note' => $note !== '' ? $note : 'メモを空にしました。',
            'created_at' => now(),
        ]);
        $this->resetValidation();
    }

    public function render()
    {
        $schemaReady = Schema::hasTable('security_anomaly_cases')
            && Schema::hasTable('security_anomaly_case_events')
            && Schema::hasTable('security_anomaly_case_signatures')
            && Schema::hasTable('security_login_observations')
            && Schema::hasTable('security_inventory_snapshots');
        $cases = null;
        $selectedCase = null;
        $counts = collect();

        if ($schemaReady) {
            $query = SecurityAnomalyCase::query()->with(['character', 'user']);

            if ($this->statusFilter === 'active') {
                $query->whereIn('status', ['detected', 'reviewing']);
            } elseif ($this->statusFilter !== '') {
                $query->where('status', $this->statusFilter);
            }
            if ($this->ruleFilter !== '') {
                $query->where('rule_key', $this->ruleFilter);
            }
            if (trim($this->search) !== '') {
                $search = trim($this->search);
                $query->where(function ($builder) use ($search): void {
                    $builder->where('title', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%")
                        ->orWhere('user_id', $search)
                        ->orWhere('character_id', $search)
                        ->orWhereHas('character', fn ($character) => $character->where('name', 'like', "%{$search}%"));
                });
            }

            $cases = $query->orderByRaw("case status when 'detected' then 0 when 'reviewing' then 1 else 2 end")
                ->latest('last_detected_at')->paginate(25);
            $counts = SecurityAnomalyCase::query()->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
            $selectedCase = $this->selectedCaseId
                ? SecurityAnomalyCase::query()->with(['character.user', 'user', 'reviewer', 'events.adminUser'])->find($this->selectedCaseId)
                : null;
        }

        return view('livewire.admin.security-anomaly-manager', [
            'schemaReady' => $schemaReady,
            'cases' => $cases,
            'selectedCase' => $selectedCase,
            'counts' => $counts,
            'ruleLabels' => $this->ruleLabels(),
            'thresholds' => config('security_anomaly_detection.rules'),
        ])->layout('components.layouts.admin');
    }

    /** @return array<string,string> */
    private function ruleLabels(): array
    {
        return [
            'rapid_battles' => '短時間大量戦闘',
            'gold_change' => 'Gold異常増減',
            'kiseki_change' => '輝石異常増減',
            'unexpected_job_exp' => '想定外Job EXP',
            'shared_ip' => '同一IP大量アカウント',
            'inventory_growth' => '装備・素材急増',
            'admin_grant_trade' => '管理者付与後の高額取引',
        ];
    }
}
