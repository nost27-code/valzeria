<?php

namespace App\Livewire;

use App\Services\HomeActionService;
use Livewire\Component;

class HomeActionPanel extends Component
{
    public function render(HomeActionService $homeActionService)
    {
        $character = auth()->check() ? auth()->user()->currentCharacter() : null;

        return view('livewire.home-action-panel', [
            'homeActions' => $character ? $homeActionService->getActions($character, 3) : [],
        ]);
    }
}
