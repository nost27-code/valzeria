<?php

namespace App\Livewire;

use App\Services\HomeActionService;
use Livewire\Attributes\On;
use Livewire\Component;

class NavMenu extends Component
{
    public $currentLocation = 'home';

    public function mount()
    {
        $routeName = request()->route()?->getName() ?? '';
        $this->currentLocation = $this->normalizeLocation(session('current_location', 'home'));

        if (str_starts_with($routeName, 'shop.')) {
            $this->currentLocation = 'shop';
        }
        if ($routeName === 'jobs.index') {
            $this->currentLocation = 'town';
        }
        if ($routeName === 'equipment.index') {
            $this->currentLocation = 'shop';
        }
    }

    #[On('tabSelectedFromOutside')]
    public function selectTab($location)
    {
        $location = $this->normalizeLocation($location);
        session(['current_location' => $location]);

        // Livewireのリクエストは /livewire-xxx/update に来るため、
        // request()->route()->getName() では 'home' を判定できない。
        // セッションでロケールを保存しているため、常にインラインで処理する。
        $this->currentLocation = $location;
        $this->dispatch('changeTab', newLocation: $location);
    }

    #[On('marketActionsSeen')]
    public function refreshMarketBadge(): void
    {
        // Re-render only. The badge count itself is calculated in render().
    }

    private function normalizeLocation(?string $location): string
    {
        $location = $location === 'job' ? 'town' : ($location ?: 'home');

        return $location === 'colosseum'
            && ! (bool) config('features.six_hero_ui_enabled', false)
                ? 'home'
                : $location;
    }

    public function render(HomeActionService $homeActionService)
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;

        return view('livewire.nav-menu', [
            'marketActionCount' => $character ? $homeActionService->marketActionCount($character) : 0,
        ]);
    }
}
