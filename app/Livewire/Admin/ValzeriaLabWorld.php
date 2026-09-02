<?php

namespace App\Livewire\Admin;

use App\Services\Admin\ValzeriaLabAccess;
use App\Services\Admin\ValzeriaLabWorldGraphService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

final class ValzeriaLabWorld extends Component
{
    use WithPagination;

    private const NODE_PER_PAGE = 40;

    private const ISSUE_PER_PAGE = 25;

    public string $search = '';

    public string $type = 'all';

    public string $issueType = 'all';

    public ?string $selectedNodeKey = null;

    public function mount(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }

    public function updatedSearch(): void
    {
        $this->guard();
        $this->resetPage('nodesPage');
    }

    public function applyFilters(): void
    {
        $this->guard();
        $this->resetPage('nodesPage');
    }

    public function updatedType(): void
    {
        $this->guard();
        $this->resetPage('nodesPage');
    }

    public function updatedIssueType(): void
    {
        $this->guard();
        $this->resetPage('issuesPage');
    }

    public function selectNode(string $nodeKey): void
    {
        $this->guard();
        if (preg_match('/^(city|area|enemy|equipment|item|material|job|title):\d+$/', $nodeKey) !== 1) {
            return;
        }
        $this->selectedNodeKey = $nodeKey;
    }

    public function clearSelection(): void
    {
        $this->guard();
        $this->selectedNodeKey = null;
    }

    public function render(ValzeriaLabWorldGraphService $service)
    {
        $this->guard();
        $type = array_key_exists($this->type, ValzeriaLabWorldGraphService::TYPE_LABELS)
            ? $this->type
            : 'all';
        $issueType = array_key_exists($this->issueType, ValzeriaLabWorldGraphService::ISSUE_TYPE_LABELS)
            ? $this->issueType
            : 'all';
        $graph = $service->build();
        $nodes = $service->filterNodes($graph, $this->search, $type);
        $issues = $service->filterIssues($graph, $issueType);

        return view('livewire.admin.valzeria-lab.world', [
            'graphCounts' => $graph['counts'],
            'typeLabels' => ValzeriaLabWorldGraphService::TYPE_LABELS,
            'issueTypeLabels' => ValzeriaLabWorldGraphService::ISSUE_TYPE_LABELS,
            'nodes' => $this->paginate($nodes, self::NODE_PER_PAGE, 'nodesPage'),
            'issues' => $this->paginate($issues, self::ISSUE_PER_PAGE, 'issuesPage'),
            'selectedDetail' => $service->detail($graph, $this->selectedNodeKey),
        ])
            ->layout('components.layouts.admin');
    }

    private function paginate(Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = max(1, $this->getPage($pageName));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => $pageName,
            ],
        );
    }

    private function guard(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }
}
