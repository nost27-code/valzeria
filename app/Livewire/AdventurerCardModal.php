<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class AdventurerCardModal extends Component
{
    public bool $isPlayerModalOpen = false;

    public ?array $playerInfo = null;

    public ?string $selectedJobBadgeTier = null;

    public bool $modalOnly = true;

    #[On('open-adventurer-card')]
    public function openPlayerModal(int $characterId): void
    {
        $this->selectedJobBadgeTier = null;
        $profileBuilder = new CityHeader();
        $profileBuilder->openPlayerModal($characterId);

        $this->playerInfo = $profileBuilder->playerInfo;
        $this->isPlayerModalOpen = $profileBuilder->isPlayerModalOpen;
    }

    #[On('open-current-adventurer-card-preview')]
    public function openCurrentCharacterPreview(): void
    {
        $character = auth()->user()?->currentCharacter();
        if ($character) {
            $this->openPlayerModal((int) $character->id);
        }
    }

    public function closePlayerModal(): void
    {
        $this->isPlayerModalOpen = false;
        $this->playerInfo = null;
        $this->selectedJobBadgeTier = null;
    }

    public function selectJobBadgeTier(string $rank): void
    {
        if (!$this->isPlayerModalOpen || !$this->playerInfo) {
            return;
        }

        $this->selectedJobBadgeTier = $this->selectedJobBadgeTier === $rank ? null : $rank;
        $tiers = $this->playerInfo['job_master_badge_tiers'] ?? [];
        foreach ($tiers as &$tier) {
            $tier['jobs'] = $this->selectedJobBadgeTier === (string) $tier['rank']
                ? CityHeader::expandCompactJobBadgeTier($tier)
                : [];
        }
        unset($tier);
        $this->playerInfo['job_master_badge_tiers'] = $tiers;
    }

    public function render()
    {
        return view('livewire.city-header', [
            'topPlayer' => null,
            'isGuestUser' => app(\App\Services\AuthService::class)->isGuestUser(auth()->user()),
        ]);
    }
}
