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
    public function loadAdventureRecords(): void
    {
        if (
            !$this->isPlayerModalOpen
            || !$this->playerInfo
            || ($this->playerInfo['adventure_records_loaded'] ?? false)
        ) {
            return;
        }

        $characterId = (int) ($this->playerInfo['id'] ?? 0);
        $payload = $characterId > 0
            ? (new CityHeader())->adventureRecordPayload($characterId)
            : null;

        if (
            !$payload
            || !$this->playerInfo
            || (int) ($this->playerInfo['id'] ?? 0) !== $characterId
        ) {
            return;
        }

        $this->playerInfo['adventure_records'] = $payload['adventure_records'];
        $this->playerInfo['card_records'] = $payload['card_records'];
        $this->playerInfo['adventure_records_loaded'] = true;
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
