<?php

namespace App\Livewire;

use App\Models\PlayerValmon;
use App\Models\Character;
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
        $champComment = null;
        $champCharacterId = $champSummary['champ']->character_id ?? null;
        if ($champCharacterId) {
            $champValmon = PlayerValmon::where('character_id', $champCharacterId)
                ->where('is_partner', true)
                ->with('master')
                ->first();
            $champComment = Character::query()
                ->whereKey($champCharacterId)
                ->value('profile_comment');
            $champComment = trim((string) $champComment) !== '' ? trim((string) $champComment) : null;
        }

        return view('livewire.champ-card', [
            'champSummary' => $champSummary,
            'champValmon'  => $champValmon,
            'champComment' => $champComment,
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageFullMessage,
        ]);
    }
}
