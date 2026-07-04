<?php

namespace App\Livewire\Admin;

use App\Services\Admin\WorldActivityMapService;
use Livewire\Component;

class WorldActivityMapManager extends Component
{
    public string $selectedCityName = '';

    public function mount(WorldActivityMapService $service): void
    {
        $data = $service->activityMap();
        $this->selectedCityName = (string) ($data['selectedCity']['name'] ?? '');
    }

    public function selectCity(string $cityName): void
    {
        $this->selectedCityName = $cityName;
    }

    public function render(WorldActivityMapService $service)
    {
        return view('livewire.admin.world-activity-map-manager', $service->activityMap($this->selectedCityName))
            ->layout('components.layouts.admin');
    }
}
