<?php

namespace App\Livewire\Admin;

use App\Models\TownMapRegistration;
use App\Services\ExplorationMapDisplayService;
use Livewire\Component;
use Livewire\WithPagination;

class PublishedMapManager extends Component
{
    use WithPagination;

    public int $perPage = 50;

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100], true) ? (int) $this->perPage : 50;
        $this->resetPage();
    }

    public function render(ExplorationMapDisplayService $displayService)
    {
        $query = TownMapRegistration::query()
            ->with(['map.owner', 'town'])
            ->where('status', 'published')
            ->where('remaining_explorations', '>', 0)
            ->where('expires_at', '>', now())
            ->orderByDesc('published_at');

        $publishedCount = (clone $query)->count();
        $registrations = $query->paginate($this->perPage);
        $mapDetails = collect($registrations->items())
            ->filter(fn (TownMapRegistration $registration) => $registration->map !== null)
            ->mapWithKeys(fn (TownMapRegistration $registration) => [
                $registration->id => $displayService->details($registration->map),
            ]);

        return view('livewire.admin.published-map-manager', [
            'registrations' => $registrations,
            'publishedCount' => $publishedCount,
            'mapDetails' => $mapDetails,
        ])->layout('components.layouts.admin');
    }
}
