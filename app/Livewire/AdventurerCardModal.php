<?php

namespace App\Livewire;

use Livewire\Attributes\On;

class AdventurerCardModal extends CityHeader
{
    public function mount(bool $showCityPanel = false, bool $modalOnly = true): void
    {
        parent::mount(false, true);
    }

    #[On('open-adventurer-card')]
    public function openPlayerModal(int $characterId): void
    {
        parent::openPlayerModal($characterId);
    }

    #[On('open-current-adventurer-card-preview')]
    public function openCurrentCharacterPreview(): void
    {
        parent::openCurrentCharacterPreview();
    }
}
