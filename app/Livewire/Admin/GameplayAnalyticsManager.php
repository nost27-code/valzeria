<?php

namespace App\Livewire\Admin;

use App\Services\Admin\GameplayAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GameplayAnalyticsManager extends Component
{
    public string $activityWindow = '30';

    public function mount(): void
    {
        $this->assertAdmin();
    }

    public function render()
    {
        $this->assertAdmin();

        return view(
            'livewire.admin.gameplay-analytics-manager',
            app(GameplayAnalyticsService::class)->analyze($this->activityWindow),
        )->layout('components.layouts.admin');
    }

    private function assertAdmin(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }
}
