<?php

namespace App\Livewire\Admin;

use App\Models\City;
use App\Models\MapIncomeLog;
use App\Models\TownMapRegistration;
use App\Services\ExplorationMapDisplayService;
use Illuminate\Support\Carbon;
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
        $incomeStats = MapIncomeLog::query()
            ->join('town_map_registrations', 'town_map_registrations.id', '=', 'map_income_logs.registration_id')
            ->where('map_income_logs.town_share', '>', 0)
            ->selectRaw('town_map_registrations.town_id, COUNT(*) as contribution_count, MAX(map_income_logs.created_at) as last_contributed_at')
            ->groupBy('town_map_registrations.town_id')
            ->get()
            ->keyBy('town_id');
        $instituteDevelopments = City::query()
            ->select(['id', 'name', 'map_institute_development'])
            ->orderByDesc('map_institute_development')
            ->orderBy('id')
            ->get()
            ->map(function (City $city) use ($incomeStats): array {
                $stats = $incomeStats->get($city->id);

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'development' => (int) ($city->map_institute_development ?? 0),
                    'contribution_count' => (int) ($stats->contribution_count ?? 0),
                    'last_contributed_at' => isset($stats->last_contributed_at)
                        ? Carbon::parse($stats->last_contributed_at)
                        : null,
                ];
            });

        return view('livewire.admin.published-map-manager', [
            'registrations' => $registrations,
            'publishedCount' => $publishedCount,
            'mapDetails' => $mapDetails,
            'instituteDevelopments' => $instituteDevelopments,
            'totalInstituteDevelopment' => $instituteDevelopments->sum('development'),
            'developedInstituteCount' => $instituteDevelopments->where('development', '>', 0)->count(),
            'totalContributionCount' => $instituteDevelopments->sum('contribution_count'),
        ])->layout('components.layouts.admin');
    }
}
