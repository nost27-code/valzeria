<?php

namespace App\Livewire;

use App\Models\PlayerValmon;
use App\Models\Character;
use App\Services\ChampBattleService;
use App\Services\CharacterIconSetService;
use App\Services\StorageCapacityService;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ChampCard extends Component
{
    #[On('character-updated')]
    public function refreshCard(): void
    {
    }

    public function render(
        ChampBattleService $champBattleService,
        StorageCapacityService $storageCapacityService,
        CharacterIconSetService $characterIconSetService,
    ) {
        $character = Auth::check() ? Auth::user()->currentCharacter() : null;
        $champSummary = $character ? $champBattleService->summary($character) : null;
        $storageSummary = $character ? $storageCapacityService->summary($character) : null;
        $storageIsFull = $storageSummary
            ? ($storageSummary['material_full'] || $storageSummary['equipment_full'])
            : false;
        $storageFullMessage = $storageIsFull
            ? $storageCapacityService->fullMessageHtml($character, $storageSummary)
            : null;

        $champValmon = null;
        $champComment = null;
        $champIconPaths = [
            CharacterIconCatalog::versionedAsset(
                $champSummary['champ']->icon_path ?? CharacterIconCatalog::DEFAULT_ICON
            ),
        ];
        $champCharacterId = $champSummary['champ']->character_id ?? null;
        if ($champCharacterId) {
            $champValmon = PlayerValmon::where('character_id', $champCharacterId)
                ->where('is_partner', true)
                ->with('master')
                ->first();
            $champCharacter = Character::query()
                ->with('iconEntitlements')
                ->find($champCharacterId);
            if ($champCharacter) {
                $champComment = trim((string) $champCharacter->profile_comment);
                $champComment = $champComment !== '' ? $champComment : null;
                $resolvedPaths = $characterIconSetService->resolvedPaths($champCharacter);
                $resolvedIconPaths = array_map(
                    fn (string $path): string => CharacterIconCatalog::versionedAsset($path),
                    array_values($resolvedPaths),
                );
                $champIconPaths = ! empty($champSummary['is_self'])
                    ? $resolvedIconPaths
                    : [$resolvedIconPaths[0]];
            }
        }

        return view('livewire.champ-card', [
            'champSummary' => $champSummary,
            'champValmon'  => $champValmon,
            'champComment' => $champComment,
            'champIconPaths' => $champIconPaths,
            'champHasPoseChoices' => count(array_unique($champIconPaths)) > 1,
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageFullMessage,
        ]);
    }
}
