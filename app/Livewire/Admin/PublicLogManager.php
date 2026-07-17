<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class PublicLogManager extends Component
{
    use WithPagination;

    public string $searchQuery = '';
    public string $typeFilter = 'all';
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $characterId = null;
    public int $perPage = 100;
    public bool $selectPage = false;
    public bool $includeProtectedLogs = false;

    /** @var array<int, bool> */
    public array $selected = [];

    /** @var list<string> */
    private array $protectedTypes = ['admin'];

    public function mount(): void
    {
        $characterId = (int) request()->query('character_id', 0);
        $this->characterId = $characterId > 0 && Character::query()->whereKey($characterId)->exists()
            ? $characterId
            : null;
    }

    public function updatedSearchQuery(): void
    {
        $this->searchQuery = trim($this->searchQuery);
        $this->resetSelectionAndPage();
    }

    public function updatedTypeFilter(): void
    {
        if (! array_key_exists($this->typeFilter, $this->typeOptions())) {
            $this->typeFilter = 'all';
        }

        if ($this->typeFilter !== 'admin') {
            $this->includeProtectedLogs = false;
        }

        $this->resetSelectionAndPage();
    }

    public function updatedIncludeProtectedLogs(): void
    {
        if ($this->typeFilter !== 'admin') {
            $this->includeProtectedLogs = false;
        }

        $this->resetSelectionAndPage();
    }

    public function updatedDateFrom(): void
    {
        $this->dateFrom = trim($this->dateFrom);
        $this->resetSelectionAndPage();
    }

    public function updatedDateTo(): void
    {
        $this->dateTo = trim($this->dateTo);
        $this->resetSelectionAndPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [50, 100, 200], true) ? (int) $this->perPage : 100;
        $this->resetSelectionAndPage();
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = [];

        if (! $value || ! $this->canManageLogs()) {
            return;
        }

        $query = $this->query();
        if (! $this->canDeleteProtectedLogs()) {
            $query->whereNotIn('public_logs.type', $this->protectedTypes);
        }

        $query
            ->forPage($this->getPage(), $this->perPage)
            ->pluck('public_logs.id')
            ->each(function ($id): void {
                $this->selected[(int) $id] = true;
            });
    }

    public function clearFilters(): void
    {
        $this->searchQuery = '';
        $this->typeFilter = 'all';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->characterId = null;
        $this->includeProtectedLogs = false;
        $this->resetSelectionAndPage();
    }

    public function deleteOne(int $logId): void
    {
        if (! $this->canManageLogs()) {
            session()->flash('error', 'ログ管理に必要なテーブルがありません。');
            return;
        }

        $target = DB::table('public_logs')->where('id', $logId)->first(['id', 'type']);
        if (! $target) {
            unset($this->selected[$logId]);
            $this->selectPage = false;
            session()->flash('error', '対象ログが見つかりませんでした。');
            return;
        }

        if ($this->isProtectedType((string) $target->type) && ! $this->canDeleteProtectedLogs()) {
            unset($this->selected[$logId]);
            $this->selectPage = false;
            session()->flash('error', '管理人ログは保護されています。種別を「管理人」に絞り、保護解除にチェックしてから削除してください。');
            return;
        }

        $deleted = DB::table('public_logs')->where('id', $logId)->delete();
        unset($this->selected[$logId]);
        $this->selectPage = false;

        session()->flash(
            $deleted > 0 ? 'status' : 'error',
            $deleted > 0 ? 'ログを1件削除しました。' : '対象ログが見つかりませんでした。'
        );
    }

    public function deleteSelected(): void
    {
        if (! $this->canManageLogs()) {
            session()->flash('error', 'ログ管理に必要なテーブルがありません。');
            return;
        }

        $ids = $this->selectedIds();
        if ($ids === []) {
            session()->flash('error', '削除するログを選択してください。');
            return;
        }

        $deleteQuery = DB::table('public_logs')->whereIn('id', $ids);
        if (! $this->canDeleteProtectedLogs()) {
            $deleteQuery->whereNotIn('type', $this->protectedTypes);
        }

        $deleted = $deleteQuery->delete();

        $this->selected = [];
        $this->selectPage = false;

        session()->flash('status', number_format($deleted) . '件のログを削除しました。');
    }

    public function render()
    {
        $logs = collect();
        $totalCount = 0;
        $canManageLogs = $this->canManageLogs();

        if ($canManageLogs) {
            $baseQuery = $this->query();
            $totalCount = (clone $baseQuery)->count();
            $logs = $baseQuery->paginate($this->perPage);
        }

        return view('livewire.admin.public-log-manager', [
            'logs' => $logs,
            'totalCount' => $totalCount,
            'typeOptions' => $this->typeOptions(),
            'selectedCount' => count($this->selectedIds()),
            'canManageLogs' => $canManageLogs,
            'protectedTypes' => $this->protectedTypes,
            'canDeleteProtectedLogs' => $this->canDeleteProtectedLogs(),
            'filteredCharacter' => $this->characterId ? Character::find($this->characterId) : null,
        ])->layout('components.layouts.admin');
    }

    private function resetSelectionAndPage(): void
    {
        $this->selected = [];
        $this->selectPage = false;
        $this->resetPage();
    }

    private function canManageLogs(): bool
    {
        return Schema::hasTable('public_logs');
    }

    private function isProtectedType(string $type): bool
    {
        return in_array($type, $this->protectedTypes, true);
    }

    private function canDeleteProtectedLogs(): bool
    {
        return $this->typeFilter === 'admin' && $this->includeProtectedLogs;
    }

    private function query()
    {
        $hasReceiverId = Schema::hasColumn('public_logs', 'receiver_id');
        $select = [
                'public_logs.id',
                'public_logs.type',
                'public_logs.message',
                'public_logs.importance',
                'public_logs.created_at',
                'sender.name as sender_name',
        ];

        $query = DB::table('public_logs')
            ->leftJoin('characters as sender', 'public_logs.character_id', '=', 'sender.id');

        if ($hasReceiverId) {
            $query->leftJoin('characters as receiver', 'public_logs.receiver_id', '=', 'receiver.id');
            $select[] = 'receiver.name as receiver_name';
        } else {
            $select[] = DB::raw('NULL as receiver_name');
        }

        $query->select($select);

        if ($this->typeFilter !== 'all') {
            $query->where('public_logs.type', $this->typeFilter);
        }

        if ($this->characterId) {
            $query->where(function ($characterQuery) use ($hasReceiverId): void {
                $characterQuery->where('public_logs.character_id', $this->characterId);

                if ($hasReceiverId) {
                    $characterQuery->orWhere('public_logs.receiver_id', $this->characterId);
                }
            });
        }

        if ($this->searchQuery !== '') {
            $search = '%' . $this->searchQuery . '%';
            $query->where(function ($q) use ($search, $hasReceiverId): void {
                $q->where('public_logs.message', 'like', $search)
                    ->orWhere('sender.name', 'like', $search);

                if ($hasReceiverId) {
                    $q->orWhere('receiver.name', 'like', $search);
                }
            });
        }

        if ($this->dateFrom !== '') {
            $from = $this->parseDate($this->dateFrom, false);
            if ($from) {
                $query->where('public_logs.created_at', '>=', $from);
            }
        }

        if ($this->dateTo !== '') {
            $to = $this->parseDate($this->dateTo, true);
            if ($to) {
                $query->where('public_logs.created_at', '<=', $to);
            }
        }

        return $query
            ->orderByDesc('public_logs.created_at')
            ->orderByDesc('public_logs.id');
    }

    private function parseDate(string $value, bool $endOfDay): ?Carbon
    {
        try {
            $date = Carbon::parse($value);
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return collect($this->selected)
            ->filter()
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return [
            'all' => 'すべて',
            'chat' => 'チャット',
            'private' => '個人(手紙)',
            'admin' => '管理人',
            'system' => 'システム',
            'newcomer' => '新規冒険者',
            'info' => 'お知らせ',
            'notice' => '運営お知らせ',
            'drop' => 'レアドロップ',
            'area' => 'エリア',
            'job' => '職業',
            'growth' => '成長',
            'arena' => '闘技場',
            'duel' => '決闘',
            'guild' => 'ギルド',
            'valmon' => 'ヴァルモン',
            'sub_area' => '亜域',
        ];
    }
}
