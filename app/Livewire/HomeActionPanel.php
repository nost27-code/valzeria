<?php

namespace App\Livewire;

use App\Services\HomeActionService;
use Livewire\Component;

class HomeActionPanel extends Component
{
    public function openDungeonArea(int $areaId): void
    {
        session([
            'current_location' => 'dungeon',
            'target_area_id' => $areaId,
        ]);

        $this->dispatch('changeTab', newLocation: 'dungeon');
        $this->dispatch('tabSelectedFromOutside', location: 'dungeon')->to(NavMenu::class);
    }

    public function render(HomeActionService $homeActionService)
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;

        return view('livewire.home-action-panel', [
            'homeActions' => $character ? $homeActionService->getActions($character, 5) : [],
        ]);
    }
}
