<?php

namespace App\Livewire;

use App\Models\Area;
use App\Services\ExplorationStateService;
use App\Services\HomeActionService;
use Livewire\Component;

class HomeActionPanel extends Component
{
    public function openDungeonArea(int $areaId, int $cityId = 0): void
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        $area = Area::find($areaId);

        if ($character && $area && $cityId > 0 && (int) ($area->city_id ?? 0) === $cityId) {
            $highestCityOrder = (int) ($character->highestCity?->sort_order
                ?? $character->currentCity?->sort_order
                ?? 0);
            $targetCityOrder = (int) ($area->city?->sort_order ?? 0);

            if ($targetCityOrder > 0
                && $targetCityOrder <= $highestCityOrder
                && (int) ($character->current_city_id ?? 0) !== $cityId) {
                $character->current_city_id = $cityId;
                $character->save();
                app(ExplorationStateService::class)->reset($character);
            }
        }

        session([
            'current_location' => 'dungeon',
            'target_area_id' => $areaId,
            'target_area_purpose' => 'next_action',
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
