<?php

namespace App\Livewire;

use App\Models\ArenaRanking;
use App\Models\Character;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.facility', [
    'title' => '闘技場ランキング',
    'headerIconImage' => 'images/icon/icon_010.webp',
    'bgImage' => 'images/facilities/02_闘技場.webp',
])]
class ColosseumRanking extends Component
{
    public ?array $selectedPlayer = null;

    public function mount(): void
    {
        session(['current_location' => 'colosseum']);
    }

    public function openPlayerModal(int $characterId): void
    {
        $character = Character::with(['jobClass', 'arenaRanking'])
            ->find($characterId);

        if (!$character) {
            $this->selectedPlayer = null;
            return;
        }

        $statusService = app(CharacterStatusService::class);
        $equipmentService = app(EquipmentService::class);
        $stats = $statusService->getFinalStats($character);
        $equippedItems = $equipmentService->getEquippedItems($character);
        $weapon = $equippedItems['weapon'] ?? null;
        $armor = $equippedItems['armor'] ?? null;
        $accessory = $equippedItems['accessory'] ?? null;

        $this->selectedPlayer = [
            'id' => $character->id,
            'name' => $character->name,
            'icon' => $character->icon_path,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'rank' => $character->arenaRanking?->rank,
            'hp' => (int) $character->current_hp,
            'max_hp' => (int) $stats['max_hp'],
            'mp' => (int) ($character->current_mp ?? 0),
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
            'str' => (int) $stats['str'],
            'def' => (int) $stats['def'],
            'mag' => (int) $stats['mag'],
            'spr' => (int) $stats['spr'],
            'agi' => (int) $stats['agi'],
            'luk' => (int) $stats['luk'],
            'weapon' => $weapon ? $weapon->displayName() : 'なし',
            'armor' => $armor ? $armor->displayName() : 'なし',
            'accessory' => $accessory ? $accessory->displayName() : 'なし',
        ];
    }

    public function closePlayerModal(): void
    {
        $this->selectedPlayer = null;
    }

    public function render()
    {
        $rankings = ArenaRanking::with(['character.jobClass'])
            ->orderBy('rank')
            ->limit(100)
            ->get();

        $myCharacter = Auth::user()->currentCharacter();

        return view('livewire.colosseum-ranking', [
            'rankings' => $rankings,
            'myCharacterId' => $myCharacter?->id,
        ]);
    }
}
