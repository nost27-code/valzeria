<?php

namespace App\Livewire;

use App\Services\ExplorationStateService;
use App\Services\HomeActionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
class MainScreenShell extends Component
{
    /** @var array<int, string> */
    public array $cachedTabLocations = [
        'town',
        'dungeon',
        'home',
        'guild',
        'colosseum',
    ];

    /** @var array<int, string> */
    public array $utilityTabLocations = [
        'move',
        'settings',
        'message',
    ];

    public string $currentLocation = 'home';

    public string $initialLocation = 'home';

    public $character;

    public function mount(): void
    {
        $this->character = Auth::user()->currentCharacter();
        $healthProbeLocation = request()->attributes->get(\App\Services\GameHealthCheckService::REQUEST_ATTRIBUTE);
        if (is_string($healthProbeLocation)) {
            $this->currentLocation = $this->normalizeLocation($healthProbeLocation);
            $this->initialLocation = $this->currentLocation;

            return;
        }

        $hasActiveExploration = $this->character
            && app(ExplorationStateService::class)->hasActiveExploration($this->character);
        $defaultLocation = $hasActiveExploration ? 'dungeon' : 'home';

        if ($hasActiveExploration
            && request()->routeIs('home')
            && !request()->boolean('skip_resume')
            && !request()->hasHeader('X-Livewire')) {
            $this->redirectRoute('battle.resume', navigate: false);

            return;
        }

        $this->currentLocation = $this->normalizeLocation(session('current_location', $defaultLocation));
        if ($hasActiveExploration && $this->currentLocation === 'home') {
            $this->currentLocation = 'dungeon';
        }
        $this->initialLocation = $this->currentLocation;
        session(['current_location' => $this->currentLocation]);

        if (!$this->character) {
            return;
        }

        $this->character->refresh();
        app(\App\Services\FerdiaMapService::class)->relocateFromDisabledRegion($this->character);

        $unlockedTitles = app(\App\Services\TitleUnlockService::class)->checkAllUnlocks($this->character);
        if (count($unlockedTitles) > 0) {
            $titleNames = collect($unlockedTitles)
                ->pluck('name')
                ->filter()
                ->implode('、');

            session()->flash('message', "過去の実績により新たな称号を獲得しました！ {$titleNames}");
        }
    }

    #[On('changeTab')]
    public function changeLocation($newLocation): void
    {
        $newLocation = $this->normalizeLocation($newLocation);
        if (!in_array($newLocation, [...$this->cachedTabLocations, ...$this->utilityTabLocations], true)) {
            return;
        }

        if ($this->character && $this->currentLocation === 'dungeon' && $newLocation !== 'dungeon') {
            $hatchedValmons = app(\App\Services\ValmonService::class)->hatchActiveEggs($this->character);
            if (!empty($hatchedValmons)) {
                $message = '卵が淡く光りはじめた……<br>';
                foreach ($hatchedValmons as $hatched) {
                    if (in_array($hatched['rarity'] ?? 'normal', ['rare', 'super_rare'], true)) {
                        $message .= '卵が強く輝いた……<br>';
                    }
                    $message .= $hatched['name'] . 'が生まれた！<br>';
                    $message .= ($hatched['already_had'] ?? false)
                        ? 'すでに仲間にしたことのあるヴァルモンです。<br>'
                        : '新しいヴァルモンが仲間になった！<br>';
                }
                session()->flash('message', $message);
            }
            app(ExplorationStateService::class)->reset($this->character);
            $this->dispatch('main-tab-invalidated', location: 'dungeon');
        }

        $this->currentLocation = $newLocation;
        session(['current_location' => $newLocation]);

        if ($this->character && $newLocation === 'guild') {
            app(HomeActionService::class)->markDeliverableNpcRequestsSeen($this->character);
            $this->dispatch('marketActionsSeen');
        }
    }

    public function render()
    {
        return view('livewire.main-screen-shell');
    }

    private function normalizeLocation(?string $location): string
    {
        return $location === 'job' ? 'town' : ($location ?: 'home');
    }
}
