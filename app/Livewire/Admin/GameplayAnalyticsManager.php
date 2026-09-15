<?php

namespace App\Livewire\Admin;

use App\Services\Admin\GameplayAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GameplayAnalyticsManager extends Component
{
    public string $activityWindow = '30';

    public string $battleContext = 'all';

    public int $currentJobId = 0;

    public string $levelBand = 'all';

    public string $shadowBonusPoints = '0';

    public function mount(): void
    {
        $this->assertAdmin();
    }

    public function render()
    {
        $this->assertAdmin();

        return view(
            'livewire.admin.gameplay-analytics-manager',
            app(GameplayAnalyticsService::class)->analyze([
                'activity_window' => $this->activityWindow,
                'battle_context' => $this->battleContext,
                'current_job_id' => $this->currentJobId,
                'level_band' => $this->levelBand,
                'shadow_bonus_points' => $this->shadowBonusPoints,
            ]),
        )->layout('components.layouts.admin');
    }

    public function updatedShadowBonusPoints(mixed $value): void
    {
        $this->shadowBonusPoints = (string) max(0, min(100, (int) $value));
    }

    private function assertAdmin(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }
}
