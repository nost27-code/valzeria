<?php

namespace App\Livewire;

use App\Services\AdventureSupportService;
use Livewire\Component;

class AdventurerDepartureSetBanner extends Component
{
    public function render(AdventureSupportService $adventureSupportService)
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;

        return view('livewire.adventurer-departure-set-banner', [
            'departureSetBanner' => $character
                ? $adventureSupportService->departureSetHomeBannerFor($character)
                : null,
        ]);
    }
}
