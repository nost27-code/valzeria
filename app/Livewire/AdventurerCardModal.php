<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class AdventurerCardModal extends Component
{
    public bool $isPlayerModalOpen = false;

    public ?array $playerInfo = null;

    public bool $modalOnly = true;

    public bool $includeStyles = true;

    #[On('open-adventurer-card')]
    #[Renderless]
    public function openPlayerModal(int $characterId): void
    {
        $profileBuilder = new CityHeader();
        $profileBuilder->openPlayerModal($characterId);

        $this->playerInfo = $profileBuilder->playerInfo;
        $this->isPlayerModalOpen = $profileBuilder->isPlayerModalOpen;
    }

    #[On('open-current-adventurer-card-preview')]
    #[Renderless]
    public function openCurrentCharacterPreview(): void
    {
        $character = auth()->user()?->currentCharacter();
        if ($character) {
            $this->openPlayerModal((int) $character->id);
        }
    }

    #[Renderless]
    public function closePlayerModal(): void
    {
        $this->isPlayerModalOpen = false;
        $this->playerInfo = null;
    }

    #[Renderless]
    public function jobBadgeTierJobs(string $rank): array
    {
        if (!$this->isPlayerModalOpen || !$this->playerInfo) {
            return [];
        }

        foreach ($this->playerInfo['job_master_badge_tiers'] ?? [] as $tier) {
            if ((string) $tier['rank'] === $rank && !($tier['locked'] ?? false)) {
                return CityHeader::expandCompactJobBadgeTier($tier);
            }
        }

        return [];
    }

    public function render()
    {
        return view('livewire.city-header', [
            'topPlayer' => null,
            'isGuestUser' => app(\App\Services\AuthService::class)->isGuestUser(auth()->user()),
        ]);
    }
}
