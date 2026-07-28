<?php

namespace App\Livewire;

use App\Models\Character;
use Livewire\Attributes\On;
use Livewire\Component;

class AdventurerCardModal extends Component
{
    public bool $isPlayerModalOpen = false;

    public ?array $playerInfo = null;

    public bool $modalOnly = true;

    #[On('open-adventurer-card')]
    public function openPlayerModal(int $characterId): void
    {
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
    }

    public function loadJobMasterBadgeTier(int $characterId, string $rank): void
    {
        if ((int) ($this->playerInfo['id'] ?? 0) !== $characterId) {
            return;
        }

        $character = Character::query()->find($characterId);
        if (! $character) {
            return;
        }

        $tier = (new CityHeader())->jobMasterBadgeTierFor($character, $rank);
        if (! $tier) {
            return;
        }

        $tiers = $this->playerInfo['job_master_badge_tiers'] ?? [];
        foreach ($tiers as $index => $currentTier) {
            if ((string) ($currentTier['rank'] ?? '') === $rank) {
                $tiers[$index] = $tier;
                break;
            }
        }

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
