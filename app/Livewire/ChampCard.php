<?php

namespace App\Livewire;

use App\Models\PlayerValmon;
use App\Services\ChampBattleService;
use App\Services\StorageCapacityService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ChampCard extends Component
{
    #[On('character-updated')]
    public function refreshCard(): void
    {
    }

    public function render(ChampBattleService $champBattleService, StorageCapacityService $storageCapacityService)
    {
        $character = Auth::check() ? Auth::user()->currentCharacter() : null;
        $champSummary = $character ? $champBattleService->summary($character) : null;
        $storageIsFull = $character ? $storageCapacityService->isFull($character) : false;
        $storageFullMessage = $storageIsFull ? $storageCapacityService->fullMessageHtml($character) : null;

        $champValmon = null;
        $champCharacterId = $champSummary['champ']->character_id ?? null;
        if ($champCharacterId) {
            $champValmon = PlayerValmon::where('character_id', $champCharacterId)
                ->where('is_partner', true)
                ->with('master')
                ->first();
        }

        return view('livewire.champ-card', [
            'champSummary' => $champSummary,
            'champValmon'  => $champValmon,
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageFullMessage,
        ]);
    }
}
