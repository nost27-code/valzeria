<?php

namespace App\Livewire;

use App\Services\ExplorationStateService;
use App\Services\HomeActionService;
use App\Services\MapExplorationItemService;
use App\Services\Nation\NationChatService;
use App\Support\TitleUnlockMessage;
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
        'nation',
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

    /** @var array<int, string> */
    public array $loadedTabLocations = [];

    public $character;

    public function mount(): void
    {
        $this->character = Auth::user()->currentCharacter();
        $healthProbeLocation = request()->attributes->get(\App\Services\GameHealthCheckService::REQUEST_ATTRIBUTE);
        if (is_string($healthProbeLocation)) {
            $this->currentLocation = $this->normalizeLocation($healthProbeLocation);
            $this->initialLocation = $this->currentLocation;
            $this->markCachedTabLoaded($this->initialLocation);

            return;
        }

        $activeMapRegistration = $this->character
            ? app(MapExplorationItemService::class)->restoreActiveSession($this->character)
            : null;
        if ($activeMapRegistration
            && request()->routeIs('home')
            && !request()->hasHeader('X-Livewire')) {
            session()->flash('message', '探索中の地図へ戻りました。');
            $this->redirectRoute('exploration-maps.published', navigate: false);

            return;
        }

        $hasActiveExploration = $this->character
            && app(ExplorationStateService::class)->hasActiveExploration($this->character);
        $defaultLocation = ($activeMapRegistration || $hasActiveExploration) ? 'dungeon' : 'home';

        if ($hasActiveExploration
            && request()->routeIs('home')
            && !request()->boolean('skip_resume')
            && !request()->hasHeader('X-Livewire')) {
            $this->redirectRoute('battle.resume', navigate: false);

            return;
        }

        $this->currentLocation = $this->normalizeLocation(session('current_location', $defaultLocation));
        if (($activeMapRegistration || $hasActiveExploration) && $this->currentLocation === 'home') {
            $this->currentLocation = 'dungeon';
        }
        $this->initialLocation = $this->currentLocation;
        $this->markCachedTabLoaded($this->initialLocation);
        session(['current_location' => $this->currentLocation]);

        if (!$this->character) {
            return;
        }

        $this->character->refresh();
        app(\App\Services\FerdiaMapService::class)->relocateFromDisabledRegion($this->character);

        $unlockedTitles = app(\App\Services\TitleUnlockService::class)->checkAllUnlocks($this->character);
        $titleMessage = TitleUnlockMessage::forPastAchievements($unlockedTitles);
        if ($titleMessage !== null) {
            session()->flash('message', $titleMessage);
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
        $this->markCachedTabLoaded($newLocation);
        session(['current_location' => $newLocation]);

        if ($this->character && $newLocation === 'guild') {
            app(HomeActionService::class)->markDeliverableNpcRequestsSeen($this->character);
            $this->dispatch('marketActionsSeen');
        }
        if ($this->character && $newLocation === 'nation') {
            app(NationChatService::class)->markRead($this->character);
            $this->dispatch('nationChatSeen');
        }
    }

    public function render()
    {
        return view('livewire.main-screen-shell');
    }

    private function markCachedTabLoaded(string $location): void
    {
        if (!in_array($location, $this->cachedTabLocations, true)
            || in_array($location, $this->loadedTabLocations, true)) {
            return;
        }

        $this->loadedTabLocations[] = $location;
    }

    private function normalizeLocation(?string $location): string
    {
        return $location === 'job' ? 'town' : ($location ?: 'home');
    }
}
