<?php

namespace App\Livewire\Admin;

use App\Services\Admin\NationRaidAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class NationRaidAnalyticsManager extends Component
{
    public string $eventKey = '';

    public int $raidDay = 0;

    public string $affiliation = 'all';

    public string $bossPhase = 'all';

    public string $adaptiveLineage = 'all';

    public string $resultStatus = 'all';

    public function mount(): void
    {
        $this->assertAdmin();
        $this->eventKey = app(NationRaidAnalyticsService::class)->latestEventKey();
    }

    public function resetFilters(): void
    {
        $this->eventKey = app(NationRaidAnalyticsService::class)->latestEventKey();
        $this->raidDay = 0;
        $this->affiliation = 'all';
        $this->bossPhase = 'all';
        $this->adaptiveLineage = 'all';
        $this->resultStatus = 'all';
    }

    public function render()
    {
        $this->assertAdmin();

        return view(
            'livewire.admin.nation-raid-analytics-manager',
            app(NationRaidAnalyticsService::class)->analyze($this->filters()),
        )->layout('components.layouts.admin');
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'event_key' => $this->eventKey,
            'raid_day' => $this->raidDay,
            'affiliation' => $this->affiliation,
            'boss_phase' => $this->bossPhase,
            'adaptive_lineage' => $this->adaptiveLineage,
            'result_status' => $this->resultStatus,
        ];
    }

    private function assertAdmin(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }
}
